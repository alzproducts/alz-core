<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Application\Jobs\Mixpanel\SyncOrderLookupTableJob;
use App\Application\Jobs\Mixpanel\SyncOrdersToMixpanelJob;
use App\Application\Jobs\Mixpanel\SyncProductLookupTableJob;
use DateTimeImmutable;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Mixpanel Schedule Definitions
 *
 * Includes:
 * - Order lookup table sync (LTV, first order, trade status)
 * - Product lookup table sync (category, supplier)
 * - Order event sync for orders missed by frontend JS SDK
 */
final class MixpanelScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $this->registerOrderLookupTableSchedule();
        $this->registerProductLookupTableSchedule();
        $this->registerOrderSyncSchedules();
    }

    /**
     * Order enrichment lookup table sync (LTV, first order, trade status).
     *
     * Runs before order event sync to ensure lookup data is fresh.
     *
     * @throws RuntimeException
     */
    private function registerOrderLookupTableSchedule(): void
    {
        Schedule::job(new SyncOrderLookupTableJob())
            ->name('sync-order-lookup-table')
            ->dailyAt('01:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(10);
    }

    /**
     * Product enrichment lookup table sync (category, supplier).
     *
     * Runs after Linnworks stock sync (which populates suppliers) and before
     * order sync to ensure product context is available for analytics.
     *
     * @throws RuntimeException
     */
    private function registerProductLookupTableSchedule(): void
    {
        Schedule::job(new SyncProductLookupTableJob())
            ->name('sync-product-lookup-table')
            ->dailyAt('01:30')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(10);
    }

    /**
     * @throws RuntimeException
     */
    private function registerOrderSyncSchedules(): void
    {
        // NIGHTLY: 28-hour lookback (24h + 4h buffer for Mixpanel ingestion delay)
        // Runs at 2:00 AM UK time — the extra 4 hours ensures no gaps between runs
        Schedule::call(static function (): void {
            SyncOrdersToMixpanelJob::dispatch(
                from: new DateTimeImmutable('-28 hours'),
                to: new DateTimeImmutable('now'),
            );
        })
            ->name('sync-orders-to-mixpanel-nightly')
            ->dailyAt('02:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);

        // WEEKLY: Last 14 days (safety net with 1 failure tolerance)
        // Deduplication via order_id_hashed + $insert_id prevents duplicates
        Schedule::call(static function (): void {
            SyncOrdersToMixpanelJob::dispatch(
                from: new DateTimeImmutable('-14 days'),
                to: new DateTimeImmutable('now'),
            );
        })
            ->name('sync-orders-to-mixpanel-weekly')
            ->weeklyOn(0, '03:00') // Sunday 3:00 AM
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(60);
    }
}
