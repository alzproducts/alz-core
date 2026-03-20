<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\Results;

use App\Domain\Catalog\Product\ValueObjects\Sku;

/**
 * A price update that failed validation or was rejected by the API.
 *
 * SKU is null for chunk-level API failures where per-SKU attribution
 * is not possible (e.g. entire batch chunk rejected by transient error).
 */
final readonly class FailedPriceUpdateResult
{
    public function __construct(
        public ?Sku $sku,
        public string $error,
    ) {}
}
