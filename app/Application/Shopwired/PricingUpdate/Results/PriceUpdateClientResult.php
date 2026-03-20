<?php

declare(strict_types=1);

namespace App\Application\Shopwired\PricingUpdate\Results;

use App\Domain\Catalog\Product\ValueObjects\PriceUpdateItemResult;
use App\Domain\Exceptions\Api\AbstractApiException;

/**
 * Result of a batch price update transport operation.
 *
 * Contains per-item results from successful batches and any transport
 * failures from failed batches. Callers should:
 * 1. Process $results for all confirmed updates (dispatch events, etc.)
 * 2. Classify each $transportFailures entry (transient vs permanent)
 *
 * Follows the same pattern as StockUpdateResult — return all valid
 * information and let the caller decide.
 */
final readonly class PriceUpdateClientResult
{
    /**
     * @param list<PriceUpdateItemResult> $results Per-item results from successful batches
     * @param list<AbstractApiException> $transportFailures All batch transport failures (empty = all succeeded)
     */
    public function __construct(
        public array $results,
        public array $transportFailures = [],
    ) {}
}
