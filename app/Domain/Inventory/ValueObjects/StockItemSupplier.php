<?php

declare(strict_types=1);

namespace App\Domain\Inventory\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Supplier information attached to a stock item.
 *
 * Represents a supplier relationship from Linnworks Suppliers data.
 * Each stock item can have multiple suppliers, with one marked as default.
 * Used for the product_enrichment Mixpanel lookup table to provide
 * supplier context for SKU-based analytics.
 *
 * @template-pattern Domain Value Object
 */
final readonly class StockItemSupplier
{
    public function __construct(
        public string $supplierId,
        public string $supplierName,
        public ?string $code,
        public ?string $supplierBarcode,
        public ?float $purchasePrice,
        public bool $isDefault,
        public ?int $leadTime,
        public ?string $supplierCurrency,
        public ?float $minPrice,
        public ?float $maxPrice,
        public ?float $averagePrice,
    ) {
        Assert::notEmpty($supplierId, 'Supplier ID cannot be empty');
        Assert::notEmpty($supplierName, 'Supplier name cannot be empty');
    }
}
