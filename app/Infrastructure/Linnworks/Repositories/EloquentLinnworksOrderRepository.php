<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\LinnworksOrderRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Linnworks\ValueObjects\LinnworksOrder;
use App\Infrastructure\Linnworks\Models\LinnworksOrderModel;
use App\Infrastructure\Repositories\AbstractEloquentRepository;

/**
 * Eloquent implementation of Linnworks order repository.
 *
 * Sync strategy: upsert by linnworks_order_id (Linnworks GUID).
 * Uses saveManyBulk() for batch upserts — flat entity with no relations.
 *
 * @extends AbstractEloquentRepository<LinnworksOrder>
 */
final class EloquentLinnworksOrderRepository extends AbstractEloquentRepository implements LinnworksOrderRepositoryInterface
{
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
     * {@inheritDoc}
     */
    public function saveOrdersBulk(array $orders): SaveManyResult
    {
        return $this->saveManyBulk(
            entities: $orders,
            entityToAttributes: fn(LinnworksOrder $order): array => $this->entityToAttributes($order),
            upsertKeys: $this->getUpsertKeys(),
        );
    }
}
