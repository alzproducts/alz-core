<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Presentation\Jobs\Mixpanel\SyncOrdersToMixpanelJob;
use DateTimeImmutable;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Mixpanel Order Sync Schedule Definitions
 *
 * Backend sync for orders missed by frontend JS SDK (ad blockers, JS errors, page abandonment).
 * Uses multi-hash matching to detect orders tracked with different hash variations
 * (SHA-256/Base64 algorithms, configured/fallback salts) - see Issue #134.
 */
final class MixpanelScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $this->registerOrderSyncSchedules();
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
