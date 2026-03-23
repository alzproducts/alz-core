<?php

declare(strict_types=1);

namespace App\Domain\Linnworks\ValueObjects;

use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;

/**
 * Linnworks purchase order header value object.
 *
 * Represents the full header data returned by the Get_PurchaseOrder endpoint.
 * Field names match the Linnworks API response (22 fields).
 *
 * @template-pattern Domain Value Object
 */
final readonly class PurchaseOrderHeader
{
    public function __construct(
        // ── Identifiers ──
        public Guid $pkPurchaseId,
        public Guid $fkSupplierId,
        public Guid $fkLocationId,
        public string $externalInvoiceNumber,

        // ── Status ──
        public PurchaseOrderStatus $status,
        public bool $locked,

        // ── Counts ──
        public int $lineCount,
        public int $deliveredLinesCount,

        // ── Financial ──
        public string $currency,
        public string $supplierReferenceNumber,
        public int $unitAmountTaxIncludedType,
        public float $postagePaid,
        public float $totalCost,
        public float $taxPaid,
        public float $shippingTaxRate,
        public float $conversionRate,
        public float $convertedShippingCost,
        public float $convertedShippingTax,
        public float $convertedOtherCost,
        public float $convertedOtherTax,
        public float $convertedGrandTotal,

        // ── Dates ──
        public ?DateTimeImmutable $dateOfPurchase = null,
        public ?DateTimeImmutable $dateOfDelivery = null,
        public ?DateTimeImmutable $quotedDeliveryDate = null,
    ) {}
}
