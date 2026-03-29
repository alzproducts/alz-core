<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\LinnworksOrderRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Linnworks\ValueObjects\LinnworksOrder;
use App\Domain\Linnworks\ValueObjects\LinnworksOrderNote;
use App\Infrastructure\Linnworks\Models\LinnworksOrderExtendedPropertyModel;
use App\Infrastructure\Linnworks\Models\LinnworksOrderItemModel;
use App\Infrastructure\Linnworks\Models\LinnworksOrderModel;
use App\Infrastructure\Repositories\AbstractEloquentRepository;

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
        return [
            'linnworks_order_id' => $entity->orderId->value,
            'num_order_id' => $entity->numOrderId->value,
            'processed' => $entity->processed,
            'last_updated' => $entity->lastUpdated,
            'processed_on' => $entity->processedOn,
            'paid_on' => $entity->paidOn,
            'received_date' => $entity->receivedDate,

            // GeneralInfo
            'reference_num' => $entity->referenceNum,
            'external_reference_num' => $entity->externalReferenceNum,
            'secondary_reference' => $entity->secondaryReference,
            'status' => $entity->status,
            'is_cancelled' => $entity->isCancelled,
            'hold_or_cancel' => $entity->holdOrCancel,
            'marker' => $entity->marker,
            'is_parked' => $entity->isParked,
            'source' => $entity->source,
            'sub_source' => $entity->subSource,
            'despatch_by_date' => $entity->despatchByDate,
            'fulfilment_location_id' => $entity->fulfilmentLocationId,
            'location' => $entity->location,
            'folder_names' => $entity->folderNames,

            // Notes (JSONB)
            'notes' => \array_map(static fn(LinnworksOrderNote $note): array => [
                'order_note_id' => $note->orderNoteId->value,
                'note_date' => $note->noteDate->format('c'),
                'internal' => $note->internal,
                'note' => $note->note,
                'created_by' => $note->createdBy,
                'note_type_id' => $note->noteTypeId,
            ], $entity->notes),

            // TotalsInfo
            'total_charge' => $entity->totalCharge,
            'subtotal' => $entity->subtotal,
            'tax' => $entity->tax,
            'payment_method' => $entity->paymentMethod,
            'payment_method_id' => $entity->paymentMethodId->value,
            'currency' => $entity->currency,

            // ShippingInfo
            'postal_service_name' => $entity->postalServiceName,
            'vendor' => $entity->vendor,
            'postage_cost' => $entity->postageCost,
            'postage_cost_ex_tax' => $entity->postageCostExTax,
            'tracking_number' => $entity->trackingNumber,

            // CustomerInfo — Shipping
            'channel_buyer_name' => $entity->channelBuyerName,
            'ship_email' => $entity->shipEmail,
            'ship_full_name' => $entity->shipFullName,
            'ship_company' => $entity->shipCompany,
            'ship_address1' => $entity->shipAddress1,
            'ship_address2' => $entity->shipAddress2,
            'ship_address3' => $entity->shipAddress3,
            'ship_town' => $entity->shipTown,
            'ship_postcode' => $entity->shipPostcode,
            'ship_country' => $entity->shipCountry,

            // CustomerInfo — Billing
            'bill_email' => $entity->billEmail,
            'bill_full_name' => $entity->billFullName,
            'bill_company' => $entity->billCompany,
            'bill_address1' => $entity->billAddress1,
            'bill_address2' => $entity->billAddress2,
            'bill_address3' => $entity->billAddress3,
            'bill_town' => $entity->billTown,
            'bill_postcode' => $entity->billPostcode,
            'bill_country' => $entity->billCountry,
        ];
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
        if ($order->items === []) {
            $this->eloquentGateway->deleteWhere(
                modelClass: LinnworksOrderItemModel::class,
                column: 'linnworks_order_id',
                value: $order->orderId->value,
            );

            return;
        }

        $rows = [];
        $rowIds = [];

        foreach ($order->items as $item) {
            $rowIds[] = $item->rowId->value;
            $rows[] = [
                'linnworks_order_id' => $order->orderId->value,
                'row_id' => $item->rowId->value,
                'parent_item_id' => $item->parentItemId?->value,
                'stock_item_id' => $item->stockItemId->value,
                'stock_item_int_id' => $item->stockItemIntId?->value,
                'item_number' => $item->itemNumber,
                'sku' => $item->sku,
                'item_source' => $item->itemSource,
                'title' => $item->title,
                'category_id' => $item->categoryId->value,
                'category_name' => $item->categoryName,
                'quantity' => $item->quantity,
                'price_per_unit' => $item->pricePerUnit,
                'unit_cost' => $item->unitCost,
                'despatch_stock_unit_cost' => $item->despatchStockUnitCost,
                'discount' => $item->discount,
                'tax_rate' => $item->taxRate,
                'cost' => $item->cost,
                'cost_inc_tax' => $item->costIncTax,
                'sales_tax' => $item->salesTax,
                'tax_cost_inclusive' => $item->taxCostInclusive,
                'discount_value' => $item->discountValue,
                'weight' => $item->weight,
                'barcode_number' => $item->barcodeNumber,
                'channel_sku' => $item->channelSku,
                'channel_title' => $item->channelTitle,
                'batch_number_scan_required' => $item->batchNumberScanRequired,
                'serial_number_scan_required' => $item->serialNumberScanRequired,
                'is_service' => $item->isService,
                'is_unlinked' => $item->isUnlinked,
                'added_date' => $item->addedDate,
                'additional_info' => $item->additionalInfo,
                'bin_racks' => $item->binRacks,
            ];
        }

        $this->eloquentGateway->upsertMany(
            modelClass: LinnworksOrderItemModel::class,
            rows: $rows,
            uniqueBy: ['row_id'],
        );

        $this->eloquentGateway->deleteWhereNotIn(
            modelClass: LinnworksOrderItemModel::class,
            whereColumn: 'linnworks_order_id',
            whereValue: $order->orderId->value,
            notInColumn: 'row_id',
            notInValues: $rowIds,
        );
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
        if ($order->extendedProperties === []) {
            $this->eloquentGateway->deleteWhere(
                modelClass: LinnworksOrderExtendedPropertyModel::class,
                column: 'linnworks_order_id',
                value: $order->orderId->value,
            );

            return;
        }

        $rows = [];
        $rowIds = [];

        foreach ($order->extendedProperties as $ep) {
            $rowIds[] = $ep->rowId->value;
            $rows[] = [
                'linnworks_order_id' => $order->orderId->value,
                'row_id' => $ep->rowId->value,
                'name' => $ep->name,
                'value' => $ep->value,
                'type' => $ep->type,
                'create_date' => $ep->createDate,
                'last_update' => $ep->lastUpdate,
                'updated_by' => $ep->updatedBy,
            ];
        }

        $this->eloquentGateway->upsertMany(
            modelClass: LinnworksOrderExtendedPropertyModel::class,
            rows: $rows,
            uniqueBy: ['row_id'],
        );

        $this->eloquentGateway->deleteWhereNotIn(
            modelClass: LinnworksOrderExtendedPropertyModel::class,
            whereColumn: 'linnworks_order_id',
            whereValue: $order->orderId->value,
            notInColumn: 'row_id',
            notInValues: $rowIds,
        );
    }
}
