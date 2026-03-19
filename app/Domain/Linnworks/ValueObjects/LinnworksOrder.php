<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Flattened Linnworks processed order value object.
 *
 * Denormalized representation of a Linnworks order with GeneralInfo,
 * TotalsInfo, ShippingInfo, and CustomerInfo flattened into a single object.
 *
 * No enums: source, vendor, and status stored as raw strings/ints.
 * Domain enums will be created in a follow-up PR after backsync
 * reveals the full value space.
 *
 * @template-pattern Domain Value Object
 */
final readonly class LinnworksOrder
{
    /**
     * @param list<string> $folderNames
     */
    public function __construct(
        // ── Order Core ──
        public Guid $orderId,
        public IntId $numOrderId,
        public bool $processed,
        public DateTimeImmutable $lastUpdated,

        // ── GeneralInfo (flattened) ──
        public string $referenceNum,
        public string $externalReferenceNum,
        public int $status,
        public bool $isCancelled,
        public string $fulfilmentLocationId,
        public string $source,
        public string $subSource,

        // ── TotalsInfo (flattened) ──
        public float $totalCharge,
        public float $subtotal,
        public float $tax,
        public string $paymentMethod,
        public Guid $paymentMethodId,
        public string $currency,

        // ── ShippingInfo (flattened) ──
        public string $postalServiceName,
        public string $vendor,
        public string $trackingNumber,
        public float $postageCost,
        public float $postageCostExTax,

        // ── CustomerInfo — Shipping Address ──
        public string $channelBuyerName,
        public string $shipEmail,
        public string $shipFullName,
        public string $shipCompany,
        public string $shipAddress1,
        public string $shipAddress2,
        public string $shipAddress3,
        public string $shipTown,
        public string $shipPostcode,
        public string $shipCountry,

        // ── CustomerInfo — Billing Address ──
        public string $billFullName,
        public string $billCompany,
        public string $billAddress1,
        public string $billAddress2,
        public string $billAddress3,
        public string $billTown,
        public string $billPostcode,
        public string $billCountry,

        // ── Nullable fields ──
        public ?string $billEmail = null,
        public ?string $secondaryReference = null,
        public ?bool $holdOrCancel = null,
        public ?bool $isParked = null,
        public ?string $location = null,
        public ?DateTimeImmutable $processedOn = null,
        public ?DateTimeImmutable $paidOn = null,
        public ?DateTimeImmutable $receivedDate = null,
        public ?DateTimeImmutable $despatchByDate = null,
        public ?int $marker = null,
        public array $folderNames = [],
    ) {}
}
