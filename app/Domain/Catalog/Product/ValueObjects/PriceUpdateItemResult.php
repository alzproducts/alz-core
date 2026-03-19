<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\ValueObjects;

use App\Domain\ValueObjects\IntId;

/**
 * Result for a single SKU from the POST products/prices API response.
 *
 * Each item in the batch response indicates whether the price was updated,
 * and if so, the ShopWired product ID and whether the SKU is a variation.
 */
final readonly class PriceUpdateItemResult
{
    /**
     * @param Sku $sku The SKU that was submitted
     * @param bool $updated Whether the API confirmed the update
     * @param IntId|null $productId ShopWired product ID (only when updated: true)
     * @param bool|null $isVariation Whether SKU is a variation (only when updated: true)
     */
    public function __construct(
        public Sku $sku,
        public bool $updated,
        public ?IntId $productId = null,
        public ?bool $isVariation = null,
    ) {}
}
