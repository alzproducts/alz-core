<?php

declare(strict_types=1);

namespace App\Application\Mixpanel\ValueObjects;

/**
 * Result of syncing orders from local database to Mixpanel.
 *
 * Tracks the pipeline: orders fetched → orders skipped (already in Mixpanel) → orders synced.
 * The skipped count represents successful deduplication against front-end tracked events.
 */
final readonly class SyncOrdersToMixpanelResult
{
    /**
     * @param int<0, max> $ordersInRange Orders found in local database for the date range
     * @param int<0, max> $skipped Orders already in Mixpanel (deduplicated via order_id_hashed)
     * @param int<0, max> $synced Orders successfully sent to Mixpanel
     * @param int<0, max> $checkoutEventsCreated Number of "Checkout Completed" events created
     * @param int<0, max> $productEventsCreated Number of "Product Purchased" events created
     */
    public function __construct(
        public int $ordersInRange,
        public int $skipped,
        public int $synced,
        public int $checkoutEventsCreated,
        public int $productEventsCreated,
    ) {}

    /**
     * Check if all orders were already tracked (nothing new to sync).
     */
    public function allSkipped(): bool
    {
        return $this->ordersInRange > 0 && $this->synced === 0;
    }

    /**
     * Check if no orders were found in the date range.
     */
    public function isEmpty(): bool
    {
        return $this->ordersInRange === 0;
    }

    /**
     * Create result for empty sync (no orders in date range).
     */
    public static function empty(): self
    {
        return new self(
            ordersInRange: 0,
            skipped: 0,
            synced: 0,
            checkoutEventsCreated: 0,
            productEventsCreated: 0,
        );
    }

    /**
     * Total events created in Mixpanel.
     *
     * @return int<0, max>
     */
    public function totalEventsCreated(): int
    {
        return $this->checkoutEventsCreated + $this->productEventsCreated;
    }
}
