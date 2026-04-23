<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\StockItemExtendedProperty;
use App\Domain\Inventory\ValueObjects\StockItemFull;
use App\Domain\Inventory\ValueObjects\StockItemSupplier;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Mappers\StockItemExtendedPropertyMapper;
use App\Infrastructure\Linnworks\Mappers\StockItemModelMapper;
use App\Infrastructure\Linnworks\Models\StockItemExtendedPropertyModel;
use App\Infrastructure\Linnworks\Models\StockItemModel;
use App\Infrastructure\Linnworks\Models\StockItemSupplierModel;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Repositories\AbstractEloquentRepository;

/**
 * Eloquent implementation of Linnworks stock item repository.
 *
 * Sync strategy:
 * - Stock items: upsert by stock_item_id (Linnworks GUID)
 * - Extended properties: delete/re-insert (catches removals in Linnworks)
 * - Suppliers: delete/re-insert (catches removals in Linnworks)
 *
 * Each save is wrapped in a transaction for atomicity.
 *
 * @extends AbstractEloquentRepository<StockItemFull>
 */
final class EloquentStockItemRepository extends AbstractEloquentRepository implements StockItemRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * Strategy:
     * 1. Upsert stock item by stock_item_id
     * 2. Delete existing extended properties for this item
     * 3. Insert fresh extended properties from domain object
     * 4. Delete existing suppliers for this item
     * 5. Insert fresh suppliers from domain object
     *
     * @param StockItemFull $entity
     */
    public function save(object $entity): void
    {
        $this->eloquentGateway->transact(function () use ($entity): void {
            // 1. Upsert stock item by stock_item_id (single query vs SELECT+INSERT)
            $this->eloquentGateway->upsertOne(
                modelClass: StockItemModel::class,
                attributes: [
                    'stock_item_id' => $entity->stockItemId,
                    ...StockItemModelMapper::toModelAttributes($entity),
                ],
                uniqueBy: ['stock_item_id'],
            );

            $this->replaceExtendedProperties($entity);
            $this->replaceSuppliers($entity);
        }, attempts: 3);
    }

    /**
     * Delete existing extended properties and re-insert fresh rows from the domain object.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function replaceExtendedProperties(StockItemFull $entity): void
    {
        $this->eloquentGateway->deleteWhere(
            modelClass: StockItemExtendedPropertyModel::class,
            column: 'stock_item_id',
            value: $entity->stockItemId,
        );

        if (!$entity->hasExtendedProperties()) {
            return;
        }

        $rows = \array_map(
            static fn(StockItemExtendedProperty $ep): array => [
                'stock_item_id' => $entity->stockItemId,
                ...StockItemExtendedPropertyMapper::toModelAttributes($ep),
            ],
            $entity->extendedProperties,
        );

        $this->eloquentGateway->insertMany(
            modelClass: StockItemExtendedPropertyModel::class,
            rows: $rows,
        );
    }

    /**
     * Delete existing suppliers and re-insert fresh rows from the domain object.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function replaceSuppliers(StockItemFull $entity): void
    {
        $this->eloquentGateway->deleteWhere(
            modelClass: StockItemSupplierModel::class,
            column: 'stock_item_id',
            value: $entity->stockItemId,
        );

        if (!$entity->hasSuppliers()) {
            return;
        }

        $rows = \array_map(
            static fn(StockItemSupplier $supplier): array => [
                'stock_item_id' => $entity->stockItemId,
                ...StockItemSupplierModel::attributesFromDomain($supplier),
            ],
            $entity->suppliers,
        );

        $this->eloquentGateway->insertMany(
            modelClass: StockItemSupplierModel::class,
            rows: $rows,
        );
    }

    /**
     * {@inheritDoc}
     *
     * Bulk-updates is_archived and is_logically_deleted in a single transaction.
     * Two-pass per flag: set true for flagged IDs, set false for any rows previously
     * set but no longer flagged (avoids blanket reset, only touches changed rows).
     *
     * @param list<Guid> $archivedIds
     * @param list<Guid> $deletedIds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function syncArchivedFlags(array $archivedIds, array $deletedIds): void
    {
        $this->eloquentGateway->transact(static function () use ($archivedIds, $deletedIds): void {
            self::applyFlagSync('is_archived', $archivedIds);
            self::applyFlagSync('is_logically_deleted', $deletedIds);
        });
    }

    /**
     * Two-pass bulk update for a single boolean flag column.
     *
     * Sets the flag to true for flagged IDs, then resets it to false for any
     * rows that previously had the flag but are no longer in the flagged set.
     *
     * @param list<Guid> $flaggedIds
     */
    private static function applyFlagSync(string $column, array $flaggedIds): void
    {
        $values = \array_map(static fn(Guid $g): string => $g->value, $flaggedIds);

        if ($values !== []) {
            StockItemModel::query()->whereIn('stock_item_id', $values)->update([$column => true]);
        }
        StockItemModel::query()
            ->whereNotIn('stock_item_id', $values)
            ->where($column, true)
            ->update([$column => false]);
    }

    /**
     * {@inheritDoc}
     *
     * Bypasses the {@see save()} override entirely — does NOT delete or
     * re-insert extended-property or supplier child rows. This preserves
     * historical child data for items transitioning from active to
     * archived state, which the regular save path would destroy.
     *
     * Every row is marked `is_archived = true` and `is_logically_deleted = true`.
     * Linnworks always co-sets both flags on archived items (verified against
     * the `StockItem` table three-bucket breakdown), so hardcoding here keeps
     * the two columns in sync without a per-row flag in the input.
     *
     * Uses {@see EloquentGateway::batchUpsertMany()}
     * at the default batch size of 500 — ~8 batches for a typical ~3.6k
     * archived catalogue.
     *
     * @param list<StockItemFull> $items
     */
    public function upsertArchivedStockItems(array $items): SaveManyResult
    {
        if ($items === []) {
            return new SaveManyResult(succeeded: 0, failed: 0, failedReferences: []);
        }

        $rows = \array_map(
            static fn(StockItemFull $item): array => [
                ...StockItemModelMapper::toModelAttributes($item),
                'is_archived' => true,
                'is_logically_deleted' => true,
            ],
            $items,
        );

        return $this->eloquentGateway->batchUpsertMany(
            modelClass: StockItemModel::class,
            rows: $rows,
            uniqueBy: ['stock_item_id'],
            identifierColumn: 'stock_item_id',
        );
    }

    /**
     * {@inheritDoc}
     *
     * @param list<Guid> $compositeIds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function syncCompositeFlags(array $compositeIds): void
    {
        $this->eloquentGateway->transact(static function () use ($compositeIds): void {
            self::applyFlagSync('is_composite', $compositeIds);
        });
    }

    protected function getModelClass(): string
    {
        return StockItemModel::class;
    }

    protected function getEntityIdentifier(object $entity): string
    {
        /** @var StockItemFull $entity */
        return $entity->stockItemId;
    }

    /**
     * {@inheritDoc}
     *
     * @param StockItemFull $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        return [
            'stock_item_id' => $entity->stockItemId,
            ...StockItemModelMapper::toModelAttributes($entity),
        ];
    }

    /**
     * {@inheritDoc}
     */
    protected function getUpsertKeys(): array
    {
        return ['stock_item_id'];
    }
}
