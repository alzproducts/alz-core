<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Application\Jobs\Shopwired\CleanupWebhookEventsJob;
use App\Application\Jobs\Shopwired\ProcessShopwiredWebhookHealthJob;
use App\Application\Jobs\Shopwired\ReconcileShopwiredProductsJob;
use App\Application\Jobs\Shopwired\SyncShopwiredCustomersJob;
use App\Application\Jobs\Shopwired\SyncShopwiredOrdersJob;
use App\Application\Jobs\Shopwired\SyncShopwiredProductsJob;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * ShopWired Integration Schedule Definitions
 *
 * Syncs orders, customers, and products from ShopWired API to local PostgreSQL.
 * Uses 2-tier frequency: monthly full sync (first Sunday) + 6-hourly quick sync (safety net).
 *
 * Webhooks handle real-time create/update/delete events. Polling serves as a safety net
 * to catch anything webhooks might miss (downtime, network issues, edge cases).
 *
 * Monthly full syncs run first Sunday at 01:00-07:30 UK time. Quick syncs are
 * skipped during this window to avoid rate limit contention.
 */
final class ShopwiredScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $skipDuringMonthlySync = $this->createMonthlySyncWindowCheck();

        $this->registerOrderSchedules($skipDuringMonthlySync);
        $this->registerCustomerSchedules($skipDuringMonthlySync);
        $this->registerProductSchedules();
        $this->registerWebhookHealthSchedule();
        $this->registerWebhookCleanupSchedule();
    }

    /**
     * Check if currently in ShopWired monthly sync window (first Sunday 01:00-08:00 UK).
     * Used to skip quick syncs during full sync to avoid rate limit contention.
     */
    private function createMonthlySyncWindowCheck(): Closure
    {
        return static function (): bool {
            $ukTime = Carbon::now('Europe/London');

            // Monthly full syncs run first Sunday at 01:00-07:30 UK time
            // Skip quick syncs during this window to give full syncs exclusive API access
            return $ukTime->isSunday()
                && $ukTime->day <= 7
                && $ukTime->hour >= 1
                && $ukTime->hour < 8;
        };
    }

    /**
     * ShopWired Order Sync (Generator-based): 2-tier frequency strategy.
     * Memory-efficient pagination from newest → oldest using generators.
     *
     * @throws RuntimeException
     */
    private function registerOrderSchedules(Closure $skipDuringMonthlySync): void
    {
        // MONTHLY: Full order sync on first Sunday at 01:00 UK time
        // Iterates all orders (newest first) until reaching already-synced orders
        Schedule::job(new SyncShopwiredOrdersJob())
            ->name('sync-shopwired-orders-full')
            ->cron('0 1 * * 0')
            ->timezone('Europe/London')
            ->when(static fn(): bool => Carbon::now('Europe/London')->day <= 7)
            ->onOneServer()
            ->withoutOverlapping(160); // ~2.7hrs lock (matches 2.5hr timeout + buffer)

        // EVERY 6 HOURS: Quick sync (5 pages, ~500 orders)
        // Safety net — webhooks handle real-time, this catches anything missed
        Schedule::job(new SyncShopwiredOrdersJob(maxPages: 5))
            ->name('sync-shopwired-orders-quick')
            ->everySixHours()
            ->onOneServer()
            ->withoutOverlapping(5)
            ->skip($skipDuringMonthlySync);
    }

    /**
     * ShopWired Webhook Health Check: daily monitoring for disabled/unverified webhooks.
     *
     * Runs at 03:00 UK time — before monthly syncs (01:00 is orders, but health check
     * is lightweight and non-conflicting). Alerts are visible before high-volume windows.
     *
     * @throws RuntimeException
     */
    private function registerWebhookHealthSchedule(): void
    {
        Schedule::job(new ProcessShopwiredWebhookHealthJob())
            ->name('check-shopwired-webhook-health')
            ->dailyAt('03:00')
            ->timezone('Europe/London')
            ->onOneServer();
    }

    /**
     * ShopWired Webhook Events Cleanup: weekly retention pruning.
     *
     * Runs Sundays at 02:00 UK time — before webhook health check (03:00) and weekly syncs (04:00+).
     * Removes idempotency records older than the retention window (90 days).
     */
    private function registerWebhookCleanupSchedule(): void
    {
        Schedule::job(new CleanupWebhookEventsJob())
            ->name('cleanup-shopwired-webhook-events')
            ->weeklyOn(Carbon::SUNDAY, '02:00')
            ->timezone('Europe/London')
            ->onOneServer();
    }

    /**
     * ShopWired Customer Sync: 2-tier frequency strategy.
     * Syncs all ~60k customers from ShopWired API to local PostgreSQL.
     * Unlike orders (date-range filtered), customers require full-sync approach.
     *
     * @throws RuntimeException
     */
    private function registerCustomerSchedules(Closure $skipDuringMonthlySync): void
    {
        // MONTHLY: Full customer sync on first Sunday at 04:00 UK time (3hrs after orders)
        // At 60 req/min rate limit, ~68k customers takes ~45-60 minutes
        Schedule::job(new SyncShopwiredCustomersJob())
            ->name('sync-shopwired-customers-full')
            ->cron('0 4 * * 0')
            ->timezone('Europe/London')
            ->when(static fn(): bool => Carbon::now('Europe/London')->day <= 7)
            ->onOneServer()
            ->withoutOverlapping(160); // ~2.7hrs lock (matches 2.5hr timeout + buffer)

        // EVERY 6 HOURS: Quick sync of recent customers (5 pages each type, ~1000 customers)
        // Safety net — webhooks handle real-time, this catches anything missed
        Schedule::job(new SyncShopwiredCustomersJob(maxTradePages: 5, maxNonTradePages: 5))
            ->name('sync-shopwired-customers-quick')
            ->everySixHours()
            ->onOneServer()
            ->withoutOverlapping(5)
            ->skip($skipDuringMonthlySync);
    }

    /**
     * ShopWired Product Sync + Reconciliation: monthly on first Sunday.
     *
     * Products are a smaller dataset (~1,500 items) and don't support date-based sorting,
     * so every sync is effectively a full sync. Reconciliation removes orphaned products.
     *
     * @throws RuntimeException
     */
    private function registerProductSchedules(): void
    {
        // MONTHLY: Full product sync on first Sunday at 07:00 UK time (after customers finish)
        Schedule::job(new SyncShopwiredProductsJob())
            ->name('sync-shopwired-products-full')
            ->cron('0 7 * * 0')
            ->timezone('Europe/London')
            ->when(static fn(): bool => Carbon::now('Europe/London')->day <= 7)
            ->onOneServer()
            ->withoutOverlapping(20); // 15min timeout + buffer

        // MONTHLY: Reconciliation on first Sunday at 07:30 UK time (after product sync)
        Schedule::job(new ReconcileShopwiredProductsJob())
            ->name('reconcile-shopwired-products')
            ->cron('30 7 * * 0')
            ->timezone('Europe/London')
            ->when(static fn(): bool => Carbon::now('Europe/London')->day <= 7)
            ->onOneServer()
            ->withoutOverlapping(10); // 5min timeout + buffer
    }
}
