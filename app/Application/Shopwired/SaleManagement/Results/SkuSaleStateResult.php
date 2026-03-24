<?php

declare(strict_types=1);

namespace App\Application\Shopwired\SaleManagement\Results;

use App\Domain\Catalog\Product\ValueObjects\Sku;

/**
 * Per-SKU sale state for reconciliation.
 */
final readonly class SkuSaleStateResult
{
    public function __construct(
        public Sku $sku,
        public bool $shouldBeInSale,
    ) {}
}
