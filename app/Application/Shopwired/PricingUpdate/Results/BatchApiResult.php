<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\Results;

use App\Domain\Catalog\Product\ValueObjects\Sku;

/**
 * Outcome of sending validated price commands to the ShopWired batch API.
 *
 * Classifies API responses into succeeded, permanent failures (API rejected),
 * and temporary failures (transient errors eligible for retry).
 */
final class BatchApiResult
{
    /** Derived from updatedSkus — no separate counter to drift out of sync. */
    public int $succeeded { get => \count($this->updatedSkus); }

    /**
     * @param list<Sku> $updatedSkus SKUs that were successfully updated
     * @param list<FailedPriceUpdateResult> $permanentFailures API rejected or SKU not found
     * @param list<FailedPriceUpdateResult> $temporaryFailures TransientApiFailure from API
     */
    public function __construct(
        public readonly array $updatedSkus,
        public readonly array $permanentFailures,
        public readonly array $temporaryFailures,
    ) {}
}
