<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Presentation\Jobs\Shopwired\SyncShopwiredCustomersJob;
use App\Presentation\Jobs\Shopwired\SyncShopwiredOrdersJob;
use Carbon\Carbon;
use Closure;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * ShopWired Integration Schedule Definitions
 *
 * Syncs orders and customers from ShopWired API to local PostgreSQL.
 * Uses 3-tier frequency: weekly (full sync), hourly (quick sync), micro (near real-time).
 *
 * Weekly syncs run Sundays during 04:00-07:00 UK time window. Micro/hourly syncs are
 * skipped during this window to avoid rate limit contention.
 */
final class ShopwiredScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $skipDuringWeeklySync = $this->createWeeklySyncWindowCheck();

        $this->registerOrderSchedules($skipDuringWeeklySync);
        $this->registerCustomerSchedules($skipDuringWeeklySync);
    }

    /**
     * Check if currently in ShopWired weekly sync window (Sundays 04:00-07:00 UK).
     * Used to skip micro/hourly syncs during full sync to avoid rate limit contention.
     */
    private function createWeeklySyncWindowCheck(): Closure
    {
        return static function (): bool {
            $ukTime = Carbon::now('Europe/London');

            // Weekly syncs run Sundays at 04:00 (orders) and 05:30 (customers) UK time
            // Skip micro/hourly during 04:00-06:59 on Sundays to give full syncs exclusive API access
            return $ukTime->isSunday() && $ukTime->hour >= 4 && $ukTime->hour < 7;
        };
    }

    /**
     * ShopWired Order Sync (Generator-based): 3-tier frequency strategy.
     * Memory-efficient pagination from newest → oldest using generators.
     *
     * @throws RuntimeException
     */
    private function registerOrderSchedules(Closure $skipDuringWeeklySync): void
    {
        // WEEKLY: Full order sync on Sundays at 4:00 AM UK time
        // Iterates all orders (newest first) until reaching already-synced orders
        Schedule::job(new SyncShopwiredOrdersJob())
            ->name('sync-shopwired-orders-weekly')
            ->weeklyOn(Carbon::SUNDAY, '04:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(75); // 75 min lock - job timeout is 70 min

        // HOURLY: Quick sync (5 pages, ~500 orders)
        // Skipped during weekly sync window
        Schedule::job(new SyncShopwiredOrdersJob(maxPages: 5))
            ->name('sync-shopwired-orders-hourly')
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping(5)
            ->skip($skipDuringWeeklySync);

        // EVERY 5 MIN: Micro sync (1 page, ~100 orders)
        // Keeps order data near real-time
        // Skipped during weekly sync window
        Schedule::job(new SyncShopwiredOrdersJob(maxPages: 1))
            ->name('sync-shopwired-orders-micro')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping(2)
            ->skip($skipDuringWeeklySync);
    }

    /**
     * ShopWired Customer Sync: 3-tier frequency strategy.
     * Syncs all ~60k customers from ShopWired API to local PostgreSQL.
     * Unlike orders (date-range filtered), customers require full-sync approach.
     *
     * @throws RuntimeException
     */
    private function registerCustomerSchedules(Closure $skipDuringWeeklySync): void
    {
        // WEEKLY: Full customer sync on Sundays at 5:30am UK time (90 min after orders)
        // At 60 req/min rate limit, ~68k customers takes ~45-60 minutes
        // Weekly ensures all customer data stays consistent
        Schedule::job(new SyncShopwiredCustomersJob())
            ->name('sync-shopwired-customers-weekly')
            ->weeklyOn(Carbon::SUNDAY, '05:30')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(75); // 75 min lock - job timeout is 70 min

        // HOURLY: Quick sync of recent customers (5 pages each type, ~1000 customers)
        // Skipped during weekly sync window
        Schedule::job(new SyncShopwiredCustomersJob(maxTradePages: 5, maxNonTradePages: 5))
            ->name('sync-shopwired-customers-hourly')
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping(5)
            ->skip($skipDuringWeeklySync);

        // EVERY 5 MIN: Micro sync (1 page each type, ~200 customers, ~30s)
        // Keeps customer data near real-time for order processing
        // Skipped during weekly sync window
        Schedule::job(new SyncShopwiredCustomersJob(maxTradePages: 1, maxNonTradePages: 1))
            ->name('sync-shopwired-customers-micro')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping(2)
            ->skip($skipDuringWeeklySync);
    }
}
