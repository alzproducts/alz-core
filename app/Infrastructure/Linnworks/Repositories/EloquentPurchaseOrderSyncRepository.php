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
use Carbon\CarbonImmutable;

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
                attributes: $this->coreToAttributes($entity->core),
                uniqueBy: $this->getUpsertKeys(),
            );

            $this->syncItems($purchaseId, $entity->core->items);
            $this->syncAdditionalCosts($purchaseId, $entity->core->additionalCosts);
            $this->syncDeliveredRecords($purchaseId, $entity->core->deliveredRecords);
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
                attributes: $this->coreToAttributes($purchaseOrder),
                uniqueBy: $this->getUpsertKeys(),
            );

            // Notes and EPs intentionally untouched — not fetched in single-call sync
            $this->syncItems($purchaseId, $purchaseOrder->items);
            $this->syncAdditionalCosts($purchaseId, $purchaseOrder->additionalCosts);
            $this->syncDeliveredRecords($purchaseId, $purchaseOrder->deliveredRecords);
        }, attempts: 3);
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
        return $this->coreToAttributes($entity->core);
    }

    protected function getUpsertKeys(): array
    {
        return ['linnworks_purchase_id'];
    }

    /**
     * Build parent row attributes from a PurchaseOrderCore.
     *
     * @return array<string, mixed>
     */
    private function coreToAttributes(PurchaseOrderCore $core): array
    {
        $header = $core->header;

        return [
            'linnworks_purchase_id' => $header->pkPurchaseId->value,
            'fk_supplier_id' => $header->fkSupplierId->value,
            'fk_location_id' => $header->fkLocationId->value,
            'external_invoice_number' => $header->externalInvoiceNumber,
            'status' => $header->status->value,
            'locked' => $header->locked,
            'line_count' => $header->lineCount,
            'delivered_lines_count' => $header->deliveredLinesCount,
            'currency' => $header->currency,
            'supplier_reference_number' => $header->supplierReferenceNumber,
            'unit_amount_tax_included_type' => $header->unitAmountTaxIncludedType,
            'postage_paid' => $header->postagePaid->toNet(),
            'total_cost' => $header->totalCost,
            'tax_paid' => $header->taxPaid,
            'shipping_tax_rate' => $header->shippingTaxRate->percentage,
            'conversion_rate' => $header->conversionRate,
            'converted_shipping_cost' => $header->convertedShippingCost,
            'converted_shipping_tax' => $header->convertedShippingTax,
            'converted_other_cost' => $header->convertedOtherCost,
            'converted_other_tax' => $header->convertedOtherTax,
            'converted_grand_total' => $header->convertedGrandTotal,
            'date_of_purchase' => $header->dateOfPurchase,
            'date_of_delivery' => $header->dateOfDelivery,
            'quoted_delivery_date' => $header->quotedDeliveryDate,
            'note_count' => $core->noteCount,
            'synced_at' => CarbonImmutable::now(),
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
            $this->eloquentGateway->deleteWhere(
                modelClass: PurchaseOrderItemModel::class,
                column: 'linnworks_purchase_id',
                value: $purchaseId,
            );

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

        $this->eloquentGateway->upsertMany(
            modelClass: PurchaseOrderItemModel::class,
            rows: $rows,
            uniqueBy: ['linnworks_purchase_item_id'],
        );

        $this->eloquentGateway->deleteWhereNotIn(
            modelClass: PurchaseOrderItemModel::class,
            whereColumn: 'linnworks_purchase_id',
            whereValue: $purchaseId,
            notInColumn: 'linnworks_purchase_item_id',
            notInValues: $itemIds,
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
            $this->eloquentGateway->deleteWhere(
                modelClass: PurchaseOrderAdditionalCostModel::class,
                column: 'linnworks_purchase_id',
                value: $purchaseId,
            );

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

        $this->eloquentGateway->upsertMany(
            modelClass: PurchaseOrderAdditionalCostModel::class,
            rows: $rows,
            uniqueBy: ['linnworks_additional_cost_item_id'],
        );

        // Nullable upsert key — skip orphan-delete when all IDs are null to avoid
        // deleting rows that can't be identified. The upsert handles dedup for non-null keys.
        if ($costIds !== []) {
            $this->eloquentGateway->deleteWhereNotIn(
                modelClass: PurchaseOrderAdditionalCostModel::class,
                whereColumn: 'linnworks_purchase_id',
                whereValue: $purchaseId,
                notInColumn: 'linnworks_additional_cost_item_id',
                notInValues: $costIds,
            );
        }
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
            $this->eloquentGateway->deleteWhere(
                modelClass: PurchaseOrderDeliveredRecordModel::class,
                column: 'linnworks_purchase_id',
                value: $purchaseId,
            );

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

        $this->eloquentGateway->upsertMany(
            modelClass: PurchaseOrderDeliveredRecordModel::class,
            rows: $rows,
            uniqueBy: ['linnworks_delivery_record_id'],
        );

        $this->eloquentGateway->deleteWhereNotIn(
            modelClass: PurchaseOrderDeliveredRecordModel::class,
            whereColumn: 'linnworks_purchase_id',
            whereValue: $purchaseId,
            notInColumn: 'linnworks_delivery_record_id',
            notInValues: $recordIds,
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
            $this->eloquentGateway->deleteWhere(
                modelClass: PurchaseOrderNoteModel::class,
                column: 'linnworks_purchase_id',
                value: $purchaseId,
            );

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

        $this->eloquentGateway->upsertMany(
            modelClass: PurchaseOrderNoteModel::class,
            rows: $rows,
            uniqueBy: ['linnworks_purchase_order_note_id'],
        );

        $this->eloquentGateway->deleteWhereNotIn(
            modelClass: PurchaseOrderNoteModel::class,
            whereColumn: 'linnworks_purchase_id',
            whereValue: $purchaseId,
            notInColumn: 'linnworks_purchase_order_note_id',
            notInValues: $noteIds,
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
            $this->eloquentGateway->deleteWhere(
                modelClass: PurchaseOrderExtendedPropertyModel::class,
                column: 'linnworks_purchase_id',
                value: $purchaseId,
            );

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

        $this->eloquentGateway->upsertMany(
            modelClass: PurchaseOrderExtendedPropertyModel::class,
            rows: $rows,
            uniqueBy: ['row_id'],
        );

        // Nullable upsert key — skip orphan-delete when all IDs are null (see syncAdditionalCosts)
        if ($rowIds !== []) {
            $this->eloquentGateway->deleteWhereNotIn(
                modelClass: PurchaseOrderExtendedPropertyModel::class,
                whereColumn: 'linnworks_purchase_id',
                whereValue: $purchaseId,
                notInColumn: 'row_id',
                notInValues: $rowIds,
            );
        }
    }
}
