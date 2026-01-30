<?php

declare(strict_types=1);

namespace App\Domain\Inventory\Commands;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\Money;
use App\Domain\ValueObjects\TaxRate;
use Webmozart\Assert\Assert;

/**
 * Command to create a new inventory item.
 *
 * Contains pure business data for a new stock item. Infrastructure-specific
 * identifiers (categoryId, stockItemId) are passed separately to the client method.
 *
 * @template-pattern Domain Command
 */
final readonly class AddInventoryItemCommand
{
    /**
     * @param Sku $sku Item number/SKU
     * @param string $title Display title for the item
     * @param Money $retailPrice Customer-facing price (tax-inclusive)
     * @param Money|null $purchasePrice Cost/purchase price from supplier (null = unknown)
     * @param TaxRate $taxRate Tax rate for this item
     * @param Gtin|null $barcode Optional barcode (GTIN/EAN/UPC)
     * @param string|null $mpn Optional manufacturer part number
     */
    public function __construct(
        public Sku $sku,
        public string $title,
        public Money $retailPrice,
        public ?Money $purchasePrice,
        public TaxRate $taxRate,
        public ?Gtin $barcode = null,
        public ?string $mpn = null,
    ) {
        Assert::notEmpty(\mb_trim($title), 'Title cannot be empty');
    }
}
