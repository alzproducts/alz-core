<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\Mixpanel\SyncOrderLookupTableJob;
use App\Infrastructure\Jobs\Mixpanel\SyncOrdersToMixpanelJob;
use App\Infrastructure\Jobs\Mixpanel\SyncProductLookupTableJob;
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
        $this->scheduleNightlyOrderSync();
        $this->scheduleWeeklyOrderSync();
    }

    /** @throws RuntimeException */
    private function scheduleNightlyOrderSync(): void
    {
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
    }

    /** @throws RuntimeException */
    private function scheduleWeeklyOrderSync(): void
    {
        Schedule::call(static function (): void {
            SyncOrdersToMixpanelJob::dispatch(
                from: new DateTimeImmutable('-14 days'),
                to: new DateTimeImmutable('now'),
            );
        })
            ->name('sync-orders-to-mixpanel-weekly')
            ->weeklyOn(0, '03:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(60);
    }
}
