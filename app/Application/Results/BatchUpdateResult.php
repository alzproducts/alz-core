<?php

declare(strict_types=1);

namespace App\Application\Results;

/**
 * Result of a batch update operation against an external API.
 *
 * Tracks successful and failed updates, distinguishing between:
 * - **Permanent failures**: Invalid data, not found, auth issues (don't retry)
 * - **Temporary failures**: Service unavailable, timeouts (worth retrying)
 *
 * @template TIdentifier of string|int
 */
final readonly class BatchUpdateResult
{
    /**
     * @param int<0, max> $total Total items processed
     * @param int<0, max> $succeeded Items successfully updated
     * @param list<array{identifier: TIdentifier, error: string}> $permanentFailures Failures that should not be retried
     * @param list<array{identifier: TIdentifier, error: string}> $temporaryFailures Failures that may succeed on retry
     */
    public function __construct(
        public int $total,
        public int $succeeded,
        public array $permanentFailures = [],
        public array $temporaryFailures = [],
    ) {}

    /**
     * Total failed items (permanent + temporary).
     *
     * @return int<0, max>
     */
    public function failed(): int
    {
        return \count($this->permanentFailures) + \count($this->temporaryFailures);
    }

    /**
     * Check if any items failed.
     */
    public function hasFailures(): bool
    {
        return $this->permanentFailures !== [] || $this->temporaryFailures !== [];
    }

    /**
     * Check if there are temporary failures worth retrying.
     */
    public function hasRetryableFailures(): bool
    {
        return $this->temporaryFailures !== [];
    }

    /**
     * Check if all items succeeded.
     */
    public function allSucceeded(): bool
    {
        return !$this->hasFailures();
    }

    /**
     * Check if all items failed.
     */
    public function allFailed(): bool
    {
        return $this->succeeded === 0 && $this->total > 0;
    }

    /**
     * Check if batch had partial success.
     */
    public function isPartialSuccess(): bool
    {
        return $this->succeeded > 0 && $this->hasFailures();
    }

    /**
     * Get identifiers of retryable (temporary) failures.
     *
     * @return list<TIdentifier>
     */
    public function getRetryableIdentifiers(): array
    {
        return \array_column($this->temporaryFailures, 'identifier');
    }

    /**
     * Get all failures combined (for logging).
     *
     * @return list<array{identifier: TIdentifier, error: string, retryable: bool}>
     */
    public function getAllFailures(): array
    {
        $permanent = \array_map(
            static fn(array $f): array => [...$f, 'retryable' => false],
            $this->permanentFailures,
        );
        $temporary = \array_map(
            static fn(array $f): array => [...$f, 'retryable' => true],
            $this->temporaryFailures,
        );

        return [...$permanent, ...$temporary];
    }

    /**
     * Create empty result (no items processed).
     *
     * @return self<string|int>
     */
    public static function empty(): self
    {
        return new self(
            total: 0,
            succeeded: 0,
        );
    }
}
