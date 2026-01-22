<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Presentation\Jobs\Linnworks\SyncLinnworksStockItemsJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Linnworks Integration Schedule Definitions
 *
 * Syncs ~4k stock items with extended properties from Linnworks to PostgreSQL.
 * Used for inventory lookups and order enrichment.
 */
final class LinnworksScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        // EVERY 8 HOURS: Full stock item sync (00:00, 08:00, 16:00 UTC)
        // Syncs inventory data 3x daily - increase frequency if near-real-time needed
        Schedule::job(new SyncLinnworksStockItemsJob())
            ->name('sync-linnworks-stock-items')
            ->cron('0 */8 * * *')
            ->onOneServer()
            ->withoutOverlapping(60); // 60 min lock - job runs 8-12 min in prod
    }
}
