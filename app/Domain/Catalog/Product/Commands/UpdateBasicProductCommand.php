<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Commands;

use App\Domain\Catalog\Product\ValueObjects\Gtin;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Inventory\ValueObjects\Weight;
use App\Domain\ValueObjects\Money;
use Webmozart\Assert\Assert;

/**
 * Update basic product/variation attributes.
 *
 * All fields nullable for partial updates. Infrastructure resolves
 * currentSku to determine product vs variation endpoint.
 */
final readonly class UpdateBasicProductCommand
{
    public function __construct(
        public string $currentSku,
        public ?Sku $newSku = null,
        public ?Money $price = null,
        public ?Money $costPrice = null,
        public ?Money $salePrice = null,
        public ?Weight $weight = null,
        public ?Gtin $gtin = null,
    ) {
        Assert::notEmpty(\mb_trim($currentSku), 'currentSku cannot be empty');
    }

    public function hasAnyUpdate(): bool
    {
        return $this->newSku !== null
            || $this->price !== null
            || $this->costPrice !== null
            || $this->salePrice !== null
            || $this->weight !== null
            || $this->gtin !== null;
    }
}
