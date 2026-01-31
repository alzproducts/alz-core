<?php

declare(strict_types=1);

namespace App\Application\Inventory\Params;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\Money;
use App\Domain\ValueObjects\TaxRate;

/**
 * Parameters to create a complete Linnworks stock item.
 *
 * Contains all data needed to create and configure a stock item in Linnworks,
 * including supplier linking, extended properties, and image. This is a
 * reusable parameter object independent of any specific source (variations, imports, etc.).
 *
 * @template-pattern Application Params DTO
 */
final readonly class CreateStockItemParams
{
    /**
     * @param Guid $categoryId Linnworks category to assign the item to
     * @param string $title Display title for the item
     * @param Money $retailPrice Customer-facing price
     * @param TaxRate $taxRate Tax rate for this item
     * @param Guid $supplierId Default supplier to link
     * @param Money|null $purchasePrice Cost/purchase price from supplier (null = unknown)
     * @param Gtin|null $barcode Optional barcode (GTIN/EAN/UPC)
     * @param string|null $mpn Optional manufacturer part number
     * @param string|null $supplierCode Supplier's code/SKU for this item
     * @param array<string, string> $extendedProperties Key-value pairs to add as EPs
     * @param string|null $imageUrl URL of image to add (null = no image)
     */
    public function __construct(
        public Guid $categoryId,
        public string $title,
        public Money $retailPrice,
        public TaxRate $taxRate,
        public Guid $supplierId,
        public ?Money $purchasePrice = null,
        public ?Gtin $barcode = null,
        public ?string $mpn = null,
        public ?string $supplierCode = null,
        public array $extendedProperties = [],
        public ?string $imageUrl = null,
    ) {}
}
