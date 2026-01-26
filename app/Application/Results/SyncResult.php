<?php

declare(strict_types=1);

namespace App\Application\Results;

/**
 * Result of a sync operation from external API to local database.
 *
 * Tracks the full pipeline: fetched from API → saved to database.
 * Used by sync use cases to report outcomes without exposing infrastructure details.
 *
 * Supports both integer IDs (ShopWired) and string GUIDs (Linnworks).
 */
final readonly class SyncResult
{
    /**
     * @param int<0, max> $fetched Number of entities fetched from external API
     * @param int<0, max> $saved Number of entities saved to local database
     * @param int<0, max> $failed Number of entities that failed to save
     * @param list<int|string> $failedReferences IDs of entities that failed
     */
    public function __construct(
        public int $fetched,
        public int $saved,
        public int $failed,
        public array $failedReferences = [],
    ) {}

    /**
     * Check if any items failed to save.
     */
    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    /**
     * Check if all fetched items were saved successfully.
     */
    public function allSaved(): bool
    {
        return $this->failed === 0 && $this->fetched === $this->saved;
    }

    /**
     * Check if nothing was fetched (empty sync window).
     */
    public function isEmpty(): bool
    {
        return $this->fetched === 0;
    }

    /**
     * Create result for empty sync (no entities found).
     */
    public static function empty(): self
    {
        return new self(fetched: 0, saved: 0, failed: 0);
    }
}
