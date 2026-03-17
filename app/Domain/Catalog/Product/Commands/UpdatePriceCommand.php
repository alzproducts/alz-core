<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Commands;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\ValueObjects\Money;
use Webmozart\Assert\Assert;

/**
 * Command to update retail pricing for a single SKU via POST products/prices.
 *
 * The API identifies items by SKU (not by product/variation ID).
 *
 * Null semantics:
 * - null = no change (field omitted from API request)
 * - Money::inclusive(0) for salePrice = clear the sale price
 */
final readonly class UpdatePriceCommand
{
    /**
     * @param Sku $sku SKU to identify the item (required by API)
     * @param Money|null $price New base price (null = no change)
     * @param Money|null $salePrice New sale price (null = no change; Money::inclusive(0) = clear)
     */
    public function __construct(
        public Sku $sku,
        public ?Money $price = null,
        public ?Money $salePrice = null,
    ) {
        // If both price and salePrice are set and salePrice > 0, salePrice must be less than price
        if ($this->price !== null && $this->salePrice !== null && ! $this->salePrice->isZero()) {
            Assert::lessThan(
                $this->salePrice->toGross(),
                $this->price->toGross(),
                \sprintf(
                    'salePrice (£%s) must be less than price (£%s)',
                    \number_format($this->salePrice->toGross(), 2),
                    \number_format($this->price->toGross(), 2),
                ),
            );
        }
    }

    public function hasAnyUpdate(): bool
    {
        return $this->price !== null
            || $this->salePrice !== null;
    }
}
