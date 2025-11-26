<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

/**
 * Retry strategy configuration for ShopWired API requests.
 *
 * ShopWired enforces a 60 requests/minute leaky bucket rate limit
 * that is shared with the legacy server. This enum provides two
 * pre-configured strategies optimized for different use cases:
 *
 * - Background: Patient retries for scheduled jobs (stock syncs)
 * - Urgent: Fast-fail for user-facing requests (order dispatch)
 *
 * @see https://shopwired.readme.io/docs/rate-limiting
 */
enum RetryStrategy: string
{
    /**
     * Patient strategy for background/scheduled jobs.
     *
     * 5 attempts with exponential backoff: 500ms → 1s → 2s → 4s → 8s
     * Use for: Stock syncs, batch imports, scheduled reconciliation
     */
    case Background = 'background';

    /**
     * Fast-fail strategy for user-facing requests.
     *
     * 2 attempts with fixed 100ms delay.
     * Use for: Order dispatch, real-time lookups, front-end serving
     */
    case Urgent = 'urgent';

    /**
     * Maximum number of retry attempts.
     */
    public function times(): int
    {
        return match ($this) {
            self::Background => 5,
            self::Urgent => 2,
        };
    }

    /**
     * Base delay between retries in milliseconds.
     *
     * For Background: Used as base for exponential calculation
     * For Urgent: Fixed delay between all attempts
     */
    public function baseDelayMs(): int
    {
        return match ($this) {
            self::Background => 500,
            self::Urgent => 100,
        };
    }

    /**
     * Whether to use exponential backoff vs fixed delay.
     */
    public function useExponentialBackoff(): bool
    {
        return $this === self::Background;
    }
}
