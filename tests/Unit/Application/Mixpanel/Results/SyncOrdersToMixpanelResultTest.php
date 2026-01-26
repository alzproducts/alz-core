<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Mixpanel\Results;

use App\Application\Mixpanel\Results\SyncOrdersToMixpanelResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * SyncOrdersToMixpanelResult Value Object Tests.
 *
 * Tests result state queries and factory methods.
 */
#[CoversClass(SyncOrdersToMixpanelResult::class)]
final class SyncOrdersToMixpanelResultTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Factory: empty()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function empty_creates_result_with_all_zero_counts(): void
    {
        $result = SyncOrdersToMixpanelResult::empty();

        $this->assertSame(0, $result->ordersInRange);
        $this->assertSame(0, $result->skipped);
        $this->assertSame(0, $result->synced);
        $this->assertSame(0, $result->checkoutEventsCreated);
        $this->assertSame(0, $result->productEventsCreated);
    }

    /*
    |--------------------------------------------------------------------------
    | State Query: isEmpty()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function isEmpty_returns_true_when_no_orders_in_range(): void
    {
        $result = new SyncOrdersToMixpanelResult(
            ordersInRange: 0,
            skipped: 0,
            synced: 0,
            checkoutEventsCreated: 0,
            productEventsCreated: 0,
        );

        $this->assertTrue($result->isEmpty());
    }

    #[Test]
    public function isEmpty_returns_false_when_orders_exist(): void
    {
        $result = new SyncOrdersToMixpanelResult(
            ordersInRange: 5,
            skipped: 3,
            synced: 2,
            checkoutEventsCreated: 2,
            productEventsCreated: 10,
        );

        $this->assertFalse($result->isEmpty());
    }

    /*
    |--------------------------------------------------------------------------
    | State Query: allSkipped()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function allSkipped_returns_true_when_all_orders_deduplicated(): void
    {
        $result = new SyncOrdersToMixpanelResult(
            ordersInRange: 10,
            skipped: 10,
            synced: 0,
            checkoutEventsCreated: 0,
            productEventsCreated: 0,
        );

        $this->assertTrue($result->allSkipped());
    }

    #[Test]
    public function allSkipped_returns_false_when_some_orders_synced(): void
    {
        $result = new SyncOrdersToMixpanelResult(
            ordersInRange: 10,
            skipped: 7,
            synced: 3,
            checkoutEventsCreated: 3,
            productEventsCreated: 15,
        );

        $this->assertFalse($result->allSkipped());
    }

    #[Test]
    public function allSkipped_returns_false_when_no_orders_in_range(): void
    {
        // Edge case: can't be "all skipped" if there were no orders
        $result = new SyncOrdersToMixpanelResult(
            ordersInRange: 0,
            skipped: 0,
            synced: 0,
            checkoutEventsCreated: 0,
            productEventsCreated: 0,
        );

        $this->assertFalse($result->allSkipped());
    }

    #[Test]
    public function allSkipped_returns_true_when_exactly_one_order_skipped(): void
    {
        // Boundary case: ordersInRange = 1, synced = 0
        // Catches mutation: ordersInRange > 0 → ordersInRange > 1
        $result = new SyncOrdersToMixpanelResult(
            ordersInRange: 1,
            skipped: 1,
            synced: 0,
            checkoutEventsCreated: 0,
            productEventsCreated: 0,
        );

        $this->assertTrue($result->allSkipped());
    }

    /*
    |--------------------------------------------------------------------------
    | Calculation: totalEventsCreated()
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function totalEventsCreated_sums_checkout_and_product_events(): void
    {
        $result = new SyncOrdersToMixpanelResult(
            ordersInRange: 5,
            skipped: 2,
            synced: 3,
            checkoutEventsCreated: 3,
            productEventsCreated: 12,
        );

        $this->assertSame(15, $result->totalEventsCreated());
    }

    #[Test]
    public function totalEventsCreated_returns_zero_when_no_events(): void
    {
        $result = SyncOrdersToMixpanelResult::empty();

        $this->assertSame(0, $result->totalEventsCreated());
    }

    /*
    |--------------------------------------------------------------------------
    | Property Exposure
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_exposes_all_properties(): void
    {
        $result = new SyncOrdersToMixpanelResult(
            ordersInRange: 100,
            skipped: 60,
            synced: 40,
            checkoutEventsCreated: 40,
            productEventsCreated: 200,
        );

        $this->assertSame(100, $result->ordersInRange);
        $this->assertSame(60, $result->skipped);
        $this->assertSame(40, $result->synced);
        $this->assertSame(40, $result->checkoutEventsCreated);
        $this->assertSame(200, $result->productEventsCreated);
    }
}
