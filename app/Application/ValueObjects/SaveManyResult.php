<?php

declare(strict_types=1);

namespace App\Application\ValueObjects;

/**
 * Result of a bulk save operation.
 *
 * Captures success/failure counts and identifies failed items by reference
 * for logging and retry purposes. Used by repositories when persisting
 * multiple entities where individual failures should not abort the batch.
 *
 * Supports both integer IDs (ShopWired) and string GUIDs (Linnworks).
 */
final readonly class SaveManyResult
{
    /**
     * @param int<0, max> $succeeded Number of entities saved successfully
     * @param int<0, max> $failed Number of entities that failed to save
     * @param list<int|string> $failedReferences IDs of entities that failed
     */
    public function __construct(
        public int $succeeded,
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
     * Check if all items were saved successfully.
     */
    public function allSucceeded(): bool
    {
        return $this->failed === 0;
    }

    /**
     * Get total number of items processed.
     */
    public function total(): int
    {
        return $this->succeeded + $this->failed;
    }

    /**
     * Create a result representing complete success.
     *
     * @param int<0, max> $count Number of items saved
     */
    public static function success(int $count): self
    {
        return new self(succeeded: $count, failed: 0);
    }

    /**
     * Create a result representing complete failure.
     *
     * @param list<int|string> $failedReferences IDs that failed
     */
    public static function failure(array $failedReferences): self
    {
        return new self(
            succeeded: 0,
            failed: \count($failedReferences),
            failedReferences: $failedReferences,
        );
    }
}
