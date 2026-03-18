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
final readonly class BatchApiResult
{
    /**
     * @param int $succeeded Count of SKUs confirmed updated by API
     * @param list<Sku> $updatedSkus SKUs that were successfully updated
     * @param list<array{sku: string, error: string}> $permanentFailures API rejected or SKU not found
     * @param list<array{sku: string, error: string}> $temporaryFailures TransientApiFailure from API
     */
    public function __construct(
        public int $succeeded,
        public array $updatedSkus,
        public array $permanentFailures,
        public array $temporaryFailures,
    ) {}
}
