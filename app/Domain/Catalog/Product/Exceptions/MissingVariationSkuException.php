<?php

declare(strict_types=1);

namespace App\Domain\Catalog\Product\Exceptions;

use App\Domain\Exceptions\DomainException;
use Override;

/**
 * Product variation is missing a required SKU.
 *
 * Thrown when a product variation has a null/empty SKU. All purchasable variants
 * MUST have an SKU for inventory tracking and order fulfillment. This is a data
 * quality issue that must be fixed in ShopWired.
 *
 * Resolution: Add the missing SKU to the variation in the ShopWired admin panel,
 * then re-run the product sync.
 */
final class MissingVariationSkuException extends DomainException
{
    /**
     * @param int $variationId ShopWired variation ID (external_id)
     * @param int $productExternalId Parent product's ShopWired ID
     */
    public function __construct(
        public readonly int $variationId,
        public readonly int $productExternalId,
    ) {
        parent::__construct('Product variation missing required SKU');
    }

    #[Override]
    public function context(): array
    {
        return [
            'variation_id' => $this->variationId,
            'product_external_id' => $this->productExternalId,
        ];
    }
}
