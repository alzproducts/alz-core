<?php

declare(strict_types=1);

namespace App\Presentation\Http\Shopwired\DTOs;

use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;
use Spatie\LaravelData\DataCollection;

/**
 * Request body for POST /api/shopwired/products/{productId}/prices.
 *
 * skuUpdates is a collection of per-SKU price changes. All SKUs must belong
 * to the same product — enforced by the use case, not validated here.
 */
final class UpdateProductPricesDTO extends Data
{
    /**
     * @param DataCollection<int, SkuPriceUpdateDTO> $skuUpdates
     */
    public function __construct(
        #[Min(1), Max(100)]
        public readonly DataCollection $skuUpdates,
        public readonly ?SaleSettingsDTO $saleSettings,
    ) {}
}
