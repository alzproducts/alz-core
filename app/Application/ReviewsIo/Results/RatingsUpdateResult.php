<?php

declare(strict_types=1);

namespace App\Application\ReviewsIo\Results;

/**
 * Result of pushing ratings to ShopWired custom fields.
 */
final readonly class RatingsUpdateResult
{
    /**
     * @param int<0, max> $processed Total products evaluated
     * @param int<0, max> $updated Products with changed ratings
     * @param int<0, max> $skipped Products with unchanged ratings
     * @param int<0, max> $failed Products that failed to update
     * @param list<int> $failedProductIds ShopWired product IDs that failed
     */
    public function __construct(
        public int $processed,
        public int $updated,
        public int $skipped,
        public int $failed,
        public array $failedProductIds = [],
    ) {}

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }
}
