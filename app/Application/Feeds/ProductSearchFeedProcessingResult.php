<?php

declare(strict_types=1);

namespace App\Application\Feeds;

/**
 * Result from a product search feed processing operation.
 *
 * Immutable value object containing metrics about feed transformation,
 * including item counts and processing duration.
 */
final readonly class ProductSearchFeedProcessingResult
{
    /**
     * @param int   $itemsProcessed    Total number of items processed in the feed
     * @param int   $titlesSubstituted Number of items where title was substituted
     * @param float $durationSeconds   Total processing time in seconds
     */
    public function __construct(
        public int $itemsProcessed,
        public int $titlesSubstituted,
        public float $durationSeconds,
    ) {}
}
