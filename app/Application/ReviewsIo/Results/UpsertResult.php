<?php

declare(strict_types=1);

namespace App\Application\ReviewsIo\Results;

/**
 * Result of an upsert operation for product ratings.
 *
 * Tracks inserted vs updated records from upsert operations.
 */
final readonly class UpsertResult
{
    /**
     * @param int<0, max> $inserted Number of new records inserted
     * @param int<0, max> $updated Number of existing records updated
     */
    public function __construct(
        public int $inserted,
        public int $updated,
    ) {}

    /**
     * Total records affected (inserted + updated).
     *
     * @return int<0, max>
     */
    public function total(): int
    {
        return $this->inserted + $this->updated;
    }

    /**
     * Create empty result (no records affected).
     */
    public static function empty(): self
    {
        return new self(inserted: 0, updated: 0);
    }
}
