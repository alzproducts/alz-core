<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\LinnworksOrderRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Linnworks\ValueObjects\LinnworksOrder;
use App\Infrastructure\Linnworks\Models\LinnworksOrderExtendedPropertyModel;
use App\Infrastructure\Linnworks\Models\LinnworksOrderItemModel;
use App\Infrastructure\Linnworks\Models\LinnworksOrderModel;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use Illuminate\Database\Eloquent\Model;
use Override;

/**
 * Eloquent implementation of Linnworks order repository.
 *
 * Sync strategy:
 * - Orders: upsert by linnworks_order_id (Linnworks GUID)
 * - Items: upsert by row_id + delete orphans (stable Linnworks RowIds)
 * - Extended properties: upsert by row_id + delete orphans
 * - Notes: JSONB column on orders table (small collection, no independent queryability)
 *
 * Each save is wrapped in a transaction for atomicity.
 *
 * @extends AbstractEloquentRepository<LinnworksOrder>
 */
final class EloquentLinnworksOrderRepository extends AbstractEloquentRepository implements LinnworksOrderRepositoryInterface
{
    /**
     * Persist an order with its child entities atomically.
     *
     * Strategy:
     * 1. Upsert order (including notes as JSONB)
     * 2. Upsert items by row_id + delete orphans
     * 3. Upsert extended properties by row_id + delete orphans
     *
     * @param LinnworksOrder $entity
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function save(object $entity): void
    {
        $this->eloquentGateway->transact(function () use ($entity): void {
            // 1. Upsert order (including notes as JSONB)
            $this->eloquentGateway->upsertOne(
                modelClass: LinnworksOrderModel::class,
                attributes: $this->entityToAttributes($entity),
                uniqueBy: $this->getUpsertKeys(),
            );

            // 2. Sync items: upsert by row_id + delete orphans
            $this->syncItems($entity);

            // 3. Sync extended properties: upsert by row_id + delete orphans
            $this->syncExtendedProperties($entity);
        }, attempts: 3);
    }

    protected function getModelClass(): string
    {
        return LinnworksOrderModel::class;
    }

    protected function getEntityIdentifier(object $entity): string
    {
        /** @var LinnworksOrder $entity */
        return $entity->orderId->value;
    }

    /**
     * {@inheritDoc}
     *
     * @param LinnworksOrder $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        return LinnworksOrderModel::attributesFromDomain($entity);
    }

    protected function getUpsertKeys(): array
    {
        return ['linnworks_order_id'];
    }

    /**
     * Sync order items: upsert by row_id, then delete orphans.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncItems(LinnworksOrder $order): void
    {
        $orderId = $order->orderId->value;

        if ($order->items === []) {
            $this->deleteAllChildrenForOrder(LinnworksOrderItemModel::class, $orderId);

            return;
        }

        $rows = [];
        $rowIds = [];

        foreach ($order->items as $item) {
            $rowIds[] = $item->rowId->value;
            $rows[] = [
                'linnworks_order_id' => $orderId,
                ...LinnworksOrderItemModel::attributesFromDomain($item),
            ];
        }

        $this->upsertChildRows(LinnworksOrderItemModel::class, $rows, 'row_id');
        $this->deleteOrphansByOrder(LinnworksOrderItemModel::class, 'row_id', $orderId, $rowIds);
    }

    /**
     * Sync order extended properties: upsert by row_id, then delete orphans.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncExtendedProperties(LinnworksOrder $order): void
    {
        $orderId = $order->orderId->value;

        if ($order->extendedProperties === []) {
            $this->deleteAllChildrenForOrder(LinnworksOrderExtendedPropertyModel::class, $orderId);

            return;
        }

        $rows = [];
        $rowIds = [];

        foreach ($order->extendedProperties as $ep) {
            $rowIds[] = $ep->rowId->value;
            $rows[] = [
                'linnworks_order_id' => $orderId,
                ...LinnworksOrderExtendedPropertyModel::attributesFromDomain($ep),
            ];
        }

        $this->upsertChildRows(LinnworksOrderExtendedPropertyModel::class, $rows, 'row_id');
        $this->deleteOrphansByOrder(LinnworksOrderExtendedPropertyModel::class, 'row_id', $orderId, $rowIds);
    }

    /**
     * @param class-string<Model> $modelClass
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function deleteAllChildrenForOrder(string $modelClass, string $orderId): void
    {
        $this->eloquentGateway->deleteWhere(
            modelClass: $modelClass,
            column: 'linnworks_order_id',
            value: $orderId,
        );
    }

    /**
     * @param class-string<Model> $modelClass
     * @param list<array<string, mixed>> $rows
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function upsertChildRows(string $modelClass, array $rows, string $uniqueColumn): void
    {
        $this->eloquentGateway->upsertMany(
            modelClass: $modelClass,
            rows: $rows,
            uniqueBy: [$uniqueColumn],
        );
    }

    /**
     * @param class-string<Model> $modelClass
     * @param list<string|int> $rowIds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function deleteOrphansByOrder(
        string $modelClass,
        string $uniqueColumn,
        string $orderId,
        array $rowIds,
    ): void {
        if ($rowIds === []) {
            return;
        }

        $this->eloquentGateway->deleteWhereNotIn(
            modelClass: $modelClass,
            whereColumn: 'linnworks_order_id',
            whereValue: $orderId,
            notInColumn: $uniqueColumn,
            notInValues: $rowIds,
        );
    }
}
