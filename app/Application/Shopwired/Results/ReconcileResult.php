<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Results;

/**
 * Result of a reconciliation operation against ShopWired API.
 *
 * Tracks the comparison between API and local data, and any orphans removed.
 * Used by reconciliation use cases to report outcomes.
 */
final readonly class ReconcileResult
{
    /**
     * @param int<0, max> $apiCount Number of entities in ShopWired API
     * @param int<0, max> $localCount Number of entities in local database
     * @param int<0, max> $orphansFound Number of orphans identified (local but not in API)
     * @param int<0, max> $orphansDeleted Number of orphans successfully deleted
     * @param list<int> $orphanIds External IDs of orphans that were deleted
     * @param bool $skipped Whether reconciliation was skipped (safety check triggered)
     */
    public function __construct(
        public int $apiCount,
        public int $localCount,
        public int $orphansFound = 0,
        public int $orphansDeleted = 0,
        public array $orphanIds = [],
        public bool $skipped = false,
    ) {}

    /**
     * Check if any orphans were found and deleted.
     */
    public function hasOrphans(): bool
    {
        return $this->orphansFound > 0;
    }

    /**
     * Check if all found orphans were successfully deleted.
     */
    public function allOrphansDeleted(): bool
    {
        return $this->orphansFound === $this->orphansDeleted;
    }

    /**
     * Check if reconciliation was skipped due to safety check.
     */
    public function wasSkipped(): bool
    {
        return $this->skipped;
    }

    /**
     * Create result when no orphans were found.
     *
     * @param int<0, max> $apiCount
     * @param int<0, max> $localCount
     */
    public static function noOrphans(int $apiCount, int $localCount): self
    {
        return new self(
            apiCount: $apiCount,
            localCount: $localCount,
        );
    }

    /**
     * Create result when reconciliation was skipped (safety check triggered).
     *
     * @param int<0, max> $localCount
     */
    public static function skipped(int $localCount): self
    {
        return new self(
            apiCount: 0,
            localCount: $localCount,
            skipped: true,
        );
    }
}
