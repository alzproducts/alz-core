<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\PurchaseOrderSyncRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderAdditionalCost;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderCore;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderDeliveredRecord;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderExtendedProperty;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderFull;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderItem;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderNote;
use App\Infrastructure\Linnworks\Models\PurchaseOrderAdditionalCostModel;
use App\Infrastructure\Linnworks\Models\PurchaseOrderDeliveredRecordModel;
use App\Infrastructure\Linnworks\Models\PurchaseOrderExtendedPropertyModel;
use App\Infrastructure\Linnworks\Models\PurchaseOrderItemModel;
use App\Infrastructure\Linnworks\Models\PurchaseOrderModel;
use App\Infrastructure\Linnworks\Models\PurchaseOrderNoteModel;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent implementation of the purchase order sync repository.
 *
 * Sync strategy:
 * - Purchase orders: upsert by linnworks_purchase_id (Linnworks GUID)
 * - Items: upsert by linnworks_purchase_item_id + delete orphans
 * - Additional costs: upsert by linnworks_additional_cost_item_id + delete orphans
 * - Delivered records: upsert by linnworks_delivery_record_id + delete orphans
 * - Notes: upsert by linnworks_purchase_order_note_id + delete orphans (full sync only)
 * - Extended properties: upsert by row_id + delete orphans (full sync only)
 *
 * saveCore() skips notes and EPs — they aren't fetched in the single-call sync
 * so their absence must not trigger deletion.
 *
 * @extends AbstractEloquentRepository<PurchaseOrderFull>
 */
final class EloquentPurchaseOrderSyncRepository extends AbstractEloquentRepository implements PurchaseOrderSyncRepositoryInterface
{
    /**
     * Persist a complete purchase order with all child entities atomically.
     *
     * @param object $entity PurchaseOrderFull
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function save(object $entity): void
    {
        /** @var PurchaseOrderFull $entity */
        $this->eloquentGateway->transact(function () use ($entity): void {
            $purchaseId = $entity->core->header->pkPurchaseId->value;

            $this->eloquentGateway->upsertOne(
                modelClass: PurchaseOrderModel::class,
                attributes: PurchaseOrderModel::attributesFromDomain($entity->core),
                uniqueBy: $this->getUpsertKeys(),
            );

            $this->syncItems($purchaseId, $entity->core->items);
            $this->syncAdditionalCosts($purchaseId, $entity->additionalCosts);
            $this->syncDeliveredRecords($purchaseId, $entity->deliveredRecords);
            $this->syncNotes($purchaseId, $entity->notes);
            $this->syncExtendedProperties($purchaseId, $entity->extendedProperties);
        }, attempts: 3);
    }

    /**
     * Persist a purchase order core without touching notes or extended properties.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function saveCore(PurchaseOrderCore $purchaseOrder): void
    {
        $this->eloquentGateway->transact(function () use ($purchaseOrder): void {
            $purchaseId = $purchaseOrder->header->pkPurchaseId->value;

            $this->eloquentGateway->upsertOne(
                modelClass: PurchaseOrderModel::class,
                attributes: PurchaseOrderModel::attributesFromDomain($purchaseOrder),
                uniqueBy: $this->getUpsertKeys(),
            );

            $this->syncItems($purchaseId, $purchaseOrder->items);
            // Additional costs, delivered records, notes, and EPs intentionally
            // untouched — Core only contains SQL-fetchable data.
        }, attempts: 3);
    }

    /**
     * Persist multiple Core purchase orders in bulk (3 DB calls total).
     *
     * No transaction wrapper — idempotent upserts self-correct on next sync.
     *
     * @param list<PurchaseOrderCore> $purchaseOrders
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function saveCoresBatch(array $purchaseOrders): void
    {
        if ($purchaseOrders === []) {
            return;
        }

        $bulk = $this->buildBulkCoreRows($purchaseOrders);

        $this->eloquentGateway->upsertMany(
            modelClass: PurchaseOrderModel::class,
            rows: $bulk['headers'],
            uniqueBy: $this->getUpsertKeys(),
        );

        if ($bulk['items'] !== []) {
            $this->eloquentGateway->upsertMany(
                modelClass: PurchaseOrderItemModel::class,
                rows: $bulk['items'],
                uniqueBy: ['linnworks_purchase_item_id'],
            );
        }

        $this->eloquentGateway->deleteWhereInAndNotIn(
            modelClass: PurchaseOrderItemModel::class,
            whereInColumn: 'linnworks_purchase_id',
            whereInValues: $bulk['purchaseIds'],
            notInColumn: 'linnworks_purchase_item_id',
            notInValues: $bulk['itemIds'],
        );
    }

    protected function getModelClass(): string
    {
        return PurchaseOrderModel::class;
    }

    protected function getEntityIdentifier(object $entity): string
    {
        /** @var PurchaseOrderFull $entity */
        return $entity->core->header->pkPurchaseId->value;
    }

    /**
     * @param PurchaseOrderFull $entity
     *
     * @return array<string, mixed>
     */
    protected function entityToAttributes(object $entity): array
    {
        return PurchaseOrderModel::attributesFromDomain($entity->core);
    }

    protected function getUpsertKeys(): array
    {
        return ['linnworks_purchase_id'];
    }

    /**
     * Flatten a list of PurchaseOrderCore into bulk-upsert-ready rows.
     *
     * @param list<PurchaseOrderCore> $purchaseOrders
     *
     * @return array{
     *     headers: list<array<string, mixed>>,
     *     items: list<array<string, mixed>>,
     *     purchaseIds: list<string>,
     *     itemIds: list<string>,
     * }
     */
    private function buildBulkCoreRows(array $purchaseOrders): array
    {
        $headers = [];
        $items = [];
        $purchaseIds = [];
        $itemIds = [];

        foreach ($purchaseOrders as $core) {
            $purchaseId = $core->header->pkPurchaseId->value;
            $purchaseIds[] = $purchaseId;
            $headers[] = PurchaseOrderModel::attributesFromDomain($core);

            foreach ($core->items as $item) {
                $itemIds[] = $item->pkPurchaseItemId->value;
                $items[] = [
                    'linnworks_purchase_id' => $purchaseId,
                    ...PurchaseOrderItemModel::attributesFromDomain($item),
                ];
            }
        }

        return [
            'headers' => $headers,
            'items' => $items,
            'purchaseIds' => $purchaseIds,
            'itemIds' => $itemIds,
        ];
    }

    /**
     * Sync purchase order items: upsert by linnworks_purchase_item_id, then orphan-delete.
     *
     * @param list<PurchaseOrderItem> $items
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncItems(string $purchaseId, array $items): void
    {
        if ($items === []) {
            $this->deleteAllChildrenForParent(PurchaseOrderItemModel::class, $purchaseId);

            return;
        }

        $rows = [];
        $itemIds = [];

        foreach ($items as $item) {
            $itemIds[] = $item->pkPurchaseItemId->value;
            $rows[] = [
                'linnworks_purchase_id' => $purchaseId,
                ...PurchaseOrderItemModel::attributesFromDomain($item),
            ];
        }

        $this->upsertChildRows(PurchaseOrderItemModel::class, $rows, 'linnworks_purchase_item_id');
        $this->deleteOrphansByParent(
            modelClass: PurchaseOrderItemModel::class,
            uniqueColumn: 'linnworks_purchase_item_id',
            purchaseId: $purchaseId,
            childIds: $itemIds,
        );
    }

    /**
     * Sync additional costs: upsert by linnworks_additional_cost_item_id, then orphan-delete.
     *
     * @param list<PurchaseOrderAdditionalCost> $costs
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncAdditionalCosts(string $purchaseId, array $costs): void
    {
        if ($costs === []) {
            $this->deleteAllChildrenForParent(PurchaseOrderAdditionalCostModel::class, $purchaseId);

            return;
        }

        $rows = [];
        $costIds = [];

        foreach ($costs as $cost) {
            if ($cost->purchaseAdditionalCostItemId !== null) {
                $costIds[] = $cost->purchaseAdditionalCostItemId;
            }

            $rows[] = [
                'linnworks_purchase_id' => $purchaseId,
                ...PurchaseOrderAdditionalCostModel::attributesFromDomain($cost),
            ];
        }

        $this->upsertChildRows(PurchaseOrderAdditionalCostModel::class, $rows, 'linnworks_additional_cost_item_id');
        $this->deleteOrphansByParent(
            modelClass: PurchaseOrderAdditionalCostModel::class,
            uniqueColumn: 'linnworks_additional_cost_item_id',
            purchaseId: $purchaseId,
            childIds: $costIds,
        );
    }

    /**
     * Sync delivered records: upsert by linnworks_delivery_record_id, then orphan-delete.
     *
     * @param list<PurchaseOrderDeliveredRecord> $records
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncDeliveredRecords(string $purchaseId, array $records): void
    {
        if ($records === []) {
            $this->deleteAllChildrenForParent(PurchaseOrderDeliveredRecordModel::class, $purchaseId);

            return;
        }

        $rows = [];
        $recordIds = [];

        foreach ($records as $record) {
            $recordIds[] = $record->pkDeliveryRecordId->value;
            $rows[] = [
                'linnworks_purchase_id' => $purchaseId,
                ...PurchaseOrderDeliveredRecordModel::attributesFromDomain($record),
            ];
        }

        $this->upsertChildRows(PurchaseOrderDeliveredRecordModel::class, $rows, 'linnworks_delivery_record_id');
        $this->deleteOrphansByParent(
            modelClass: PurchaseOrderDeliveredRecordModel::class,
            uniqueColumn: 'linnworks_delivery_record_id',
            purchaseId: $purchaseId,
            childIds: $recordIds,
        );
    }

    /**
     * Sync notes: upsert by linnworks_purchase_order_note_id, then orphan-delete.
     *
     * Only called from save(PurchaseOrderFull) — NOT from saveCore().
     *
     * @param list<PurchaseOrderNote> $notes
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncNotes(string $purchaseId, array $notes): void
    {
        if ($notes === []) {
            $this->deleteAllChildrenForParent(PurchaseOrderNoteModel::class, $purchaseId);

            return;
        }

        $rows = [];
        $noteIds = [];

        foreach ($notes as $note) {
            $noteIds[] = $note->pkPurchaseOrderNoteId->value;
            $rows[] = [
                'linnworks_purchase_id' => $purchaseId,
                ...PurchaseOrderNoteModel::attributesFromDomain($note),
            ];
        }

        $this->upsertChildRows(PurchaseOrderNoteModel::class, $rows, 'linnworks_purchase_order_note_id');
        $this->deleteOrphansByParent(
            modelClass: PurchaseOrderNoteModel::class,
            uniqueColumn: 'linnworks_purchase_order_note_id',
            purchaseId: $purchaseId,
            childIds: $noteIds,
        );
    }

    /**
     * Sync extended properties: upsert by row_id, then orphan-delete.
     *
     * Only called from save(PurchaseOrderFull) — NOT from saveCore().
     *
     * @param list<PurchaseOrderExtendedProperty> $extendedProperties
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncExtendedProperties(string $purchaseId, array $extendedProperties): void
    {
        if ($extendedProperties === []) {
            $this->deleteAllChildrenForParent(PurchaseOrderExtendedPropertyModel::class, $purchaseId);

            return;
        }

        $rows = [];
        $rowIds = [];

        foreach ($extendedProperties as $ep) {
            if ($ep->rowId !== null) {
                $rowIds[] = $ep->rowId;
            }

            $rows[] = [
                'linnworks_purchase_id' => $purchaseId,
                ...PurchaseOrderExtendedPropertyModel::attributesFromDomain($ep),
            ];
        }

        $this->upsertChildRows(PurchaseOrderExtendedPropertyModel::class, $rows, 'row_id');
        $this->deleteOrphansByParent(
            modelClass: PurchaseOrderExtendedPropertyModel::class,
            uniqueColumn: 'row_id',
            purchaseId: $purchaseId,
            childIds: $rowIds,
        );
    }

    /**
     * Delete all child rows for a given purchase order (used when the incoming
     * child list is empty and represents the authoritative state).
     *
     * @param class-string<Model> $modelClass
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function deleteAllChildrenForParent(string $modelClass, string $purchaseId): void
    {
        $this->eloquentGateway->deleteWhere(
            modelClass: $modelClass,
            column: 'linnworks_purchase_id',
            value: $purchaseId,
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
     * @param list<string|int> $childIds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function deleteOrphansByParent(
        string $modelClass,
        string $uniqueColumn,
        string $purchaseId,
        array $childIds,
    ): void {
        if ($childIds === []) {
            return;
        }

        $this->eloquentGateway->deleteWhereNotIn(
            modelClass: $modelClass,
            whereColumn: 'linnworks_purchase_id',
            whereValue: $purchaseId,
            notInColumn: $uniqueColumn,
            notInValues: $childIds,
        );
    }
}
