<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Linnworks\ValueObjects\LinnworksOrder;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
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
    ) {}

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
            isCancelled: $this->generalInfo->isCancelled,
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

            // CustomerInfo — Billing
            billEmail: $billing->emailAddress,
            billFullName: $billing->fullName,
            billCompany: $billing->company,
            billAddress1: $billing->address1,
            billAddress2: $billing->address2,
            billAddress3: $billing->address3,
            billTown: $billing->town,
            billPostcode: $billing->postCode,
            billCountry: $billing->country,

            // Nullable fields
            processedOn: self::parseDate($this->processedOn),
            paidOn: self::parseDate($this->paidOn),
            receivedDate: self::parseDate($this->generalInfo->receivedDate),
            despatchByDate: self::parseDate($this->generalInfo->despatchByDate),
            marker: $this->generalInfo->marker,
            folderNames: $this->folderName,
        );
    }

    private static function parseDate(?string $value): ?DateTimeImmutable
    {
        if ($value === null || $value === '') {
            return null;
        }

        return CarbonImmutable::parse($value)->toDateTimeImmutable();
    }
}
