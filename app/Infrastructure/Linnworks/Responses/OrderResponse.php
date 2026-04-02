<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Linnworks\ValueObjects\LinnworksOrder;
use App\Domain\Linnworks\ValueObjects\LinnworksOrderExtendedProperty;
use App\Domain\Linnworks\ValueObjects\LinnworksOrderItem;
use App\Domain\Linnworks\ValueObjects\LinnworksOrderNote;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\LinnworksDateParser;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

/**
 * Individual order DTO from the v2 GetOrders endpoint.
 *
 * Implements DomainConvertibleInterface to flatten nested sub-objects
 * (GeneralInfo, TotalsInfo, ShippingInfo, CustomerInfo) into the
 * flat LinnworksOrder domain value object.
 *
 * @template-pattern Infrastructure Response DTO
 */
#[MapInputName(PascalCaseMapper::class)]
final class OrderResponse extends Data implements DomainConvertibleInterface
{
    /**
     * @param list<string> $folderName
     * @param list<OrderItemResponse>|null $items
     * @param list<OrderExtendedPropertyResponse>|null $extendedProperties
     * @param list<OrderNoteResponse>|null $notes
     */
    public function __construct(
        public readonly string $orderId,
        public readonly int $numOrderId,
        public readonly bool $processed,
        public readonly string $lastUpdated,
        public readonly string $fulfilmentLocationId,
        public readonly OrderGeneralInfoResponse $generalInfo,
        public readonly OrderTotalsInfoResponse $totalsInfo,
        public readonly OrderShippingInfoResponse $shippingInfo,
        public readonly OrderCustomerInfoResponse $customerInfo,
        public readonly ?string $processedOn = null,
        public readonly ?string $paidOn = null,
        public readonly array $folderName = [],
        #[DataCollectionOf(OrderItemResponse::class)]
        public readonly ?array $items = null,
        #[DataCollectionOf(OrderExtendedPropertyResponse::class)]
        public readonly ?array $extendedProperties = null,
        #[DataCollectionOf(OrderNoteResponse::class)]
        public readonly ?array $notes = null,
    ) {}

    /**
     * @throws InvalidApiResponseException When date parsing fails
     */
    public function toDomain(): LinnworksOrder
    {
        $shipping = $this->customerInfo->address;
        $billing = $this->customerInfo->billingAddress;

        return new LinnworksOrder(
            // Order Core
            orderId: new Guid($this->orderId),
            numOrderId: IntId::from($this->numOrderId),
            processed: $this->processed,
            lastUpdated: CarbonImmutable::parse($this->lastUpdated)->toDateTimeImmutable(),

            // GeneralInfo
            referenceNum: $this->generalInfo->referenceNum,
            externalReferenceNum: $this->generalInfo->externalReferenceNum,
            secondaryReference: $this->generalInfo->secondaryReference,
            status: $this->generalInfo->status,
            isCancelled: $this->generalInfo->isCancelled ?? false,
            holdOrCancel: $this->generalInfo->holdOrCancel,
            isParked: $this->generalInfo->isParked,
            source: $this->generalInfo->source,
            subSource: $this->generalInfo->subSource,
            fulfilmentLocationId: $this->fulfilmentLocationId,
            location: $this->generalInfo->location,

            // TotalsInfo
            totalCharge: $this->totalsInfo->totalCharge,
            subtotal: $this->totalsInfo->subtotal,
            tax: $this->totalsInfo->tax,
            paymentMethod: $this->totalsInfo->paymentMethod,
            paymentMethodId: new Guid($this->totalsInfo->paymentMethodId),
            currency: $this->totalsInfo->currency,

            // ShippingInfo
            postalServiceName: $this->shippingInfo->postalServiceName,
            vendor: $this->shippingInfo->vendor,
            trackingNumber: $this->shippingInfo->trackingNumber,

            // TotalsInfo — postage costs live here in the v2 API
            postageCost: $this->totalsInfo->postageCost,
            postageCostExTax: $this->totalsInfo->postageCostExTax,

            // CustomerInfo — Shipping
            channelBuyerName: $this->customerInfo->channelBuyerName,
            shipEmail: $shipping->emailAddress ?? '',
            shipFullName: $shipping->fullName,
            shipCompany: $shipping->company,
            shipAddress1: $shipping->address1,
            shipAddress2: $shipping->address2,
            shipAddress3: $shipping->address3,
            shipTown: $shipping->town,
            shipPostcode: $shipping->postCode,
            shipCountry: $shipping->country,

            // CustomerInfo — Billing (nullable for redacted/historical orders)
            billEmail: $billing !== null ? $billing->emailAddress : null,
            billFullName: $billing !== null ? $billing->fullName : '',
            billCompany: $billing !== null ? $billing->company : '',
            billAddress1: $billing !== null ? $billing->address1 : '',
            billAddress2: $billing !== null ? $billing->address2 : '',
            billAddress3: $billing !== null ? $billing->address3 : '',
            billTown: $billing !== null ? $billing->town : '',
            billPostcode: $billing !== null ? $billing->postCode : '',
            billCountry: $billing !== null ? $billing->country : '',

            // Nullable fields
            processedOn: LinnworksDateParser::parse($this->processedOn),
            paidOn: LinnworksDateParser::parse($this->paidOn),
            receivedDate: LinnworksDateParser::parse($this->generalInfo->receivedDate),
            despatchByDate: LinnworksDateParser::parse($this->generalInfo->despatchByDate),
            marker: $this->generalInfo->marker,
            folderNames: $this->folderName,

            // Child collections
            items: $this->mapItems(),
            extendedProperties: $this->mapExtendedProperties(),
            notes: $this->mapNotes(),
        );
    }

    /**
     * Flatten and map order items, including composite sub-items.
     *
     * @return list<LinnworksOrderItem>
     */
    private function mapItems(): array
    {
        if ($this->items === null || $this->items === []) {
            return [];
        }

        /** @var list<LinnworksOrderItem> $result */
        $result = [];

        foreach ($this->items as $itemResponse) {
            /** @var OrderItemResponse $itemResponse */
            \array_push($result, ...$itemResponse->toDomain());
        }

        return $result;
    }

    /**
     * @return list<LinnworksOrderExtendedProperty>
     *
     * @throws InvalidApiResponseException
     */
    private function mapExtendedProperties(): array
    {
        if ($this->extendedProperties === null || $this->extendedProperties === []) {
            return [];
        }

        /** @var list<LinnworksOrderExtendedProperty> $result */
        $result = [];

        foreach ($this->extendedProperties as $epResponse) {
            /** @var OrderExtendedPropertyResponse $epResponse */
            $result[] = $epResponse->toDomain();
        }

        return $result;
    }

    /**
     * @return list<LinnworksOrderNote>
     */
    private function mapNotes(): array
    {
        if ($this->notes === null || $this->notes === []) {
            return [];
        }

        /** @var list<LinnworksOrderNote> $result */
        $result = [];

        foreach ($this->notes as $noteResponse) {
            /** @var OrderNoteResponse $noteResponse */
            $result[] = $noteResponse->toDomain();
        }

        return $result;
    }
}
