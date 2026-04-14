<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\Results;

use App\Domain\ValueObjects\IntId;

/**
 * Result of evaluating a product's sale state against expected state.
 *
 * Describes what corrections are needed to bring ShopWired
 * in sync with the product's current pricing.
 */
final readonly class ProductSaleStateResult
{
    public function __construct(
        public IntId $productId,
        public bool $shouldBeOnSale,
        public bool $needsAddToSale,
        public bool $needsRemoveFromSale,
    ) {}

    public function needsCorrection(): bool
    {
        return $this->needsAddToSale || $this->needsRemoveFromSale;
    }
}
