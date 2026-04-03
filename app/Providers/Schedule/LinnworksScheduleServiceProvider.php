<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Application\Linnworks\Enums\OrderSyncTier;
use App\Infrastructure\Jobs\Linnworks\SyncAllOpenLinnworksOrdersJob;
use App\Infrastructure\Jobs\Linnworks\SyncAllPurchaseOrdersJob;
use App\Infrastructure\Jobs\Linnworks\SyncArchivedStockItemFlagsJob;
use App\Infrastructure\Jobs\Linnworks\SyncFastPurchaseOrdersJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksOrdersByCursorJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksOrdersJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksStockItemsJob;
use App\Infrastructure\Jobs\Linnworks\SyncLinnworksSuppliersJob;
use App\Infrastructure\Jobs\Linnworks\SyncPurchaseOrdersByDateRangeJob;
use App\Infrastructure\Jobs\Linnworks\SyncStockItemsWithCursorJob;
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
        $this->registerStockSchedules();
        $this->registerOrderSchedules();
        $this->registerPurchaseOrderSchedules();
    }

    /**
     * Register stock item and supplier sync schedules.
     */
    private function registerStockSchedules(): void
    {
        // DAILY: Full stock item sync at midnight UTC — safety net for cursor-based sync
        Schedule::job(new SyncLinnworksStockItemsJob())
            ->name('sync-linnworks-stock-items')
            ->daily()->onOneServer()->withoutOverlapping(60);

        // HOURLY: Supplier directory sync — full-replace, small dataset
        Schedule::job(new SyncLinnworksSuppliersJob())
            ->name('sync-linnworks-suppliers')
            ->hourly()->onOneServer()->withoutOverlapping(10);

        $this->registerArchivedFlagsSchedule();

        // EVERY 5 MIN: Cursor-based incremental stock item sync
        Schedule::job(new SyncStockItemsWithCursorJob())
            ->name('sync-stock-items-with-cursor')
            ->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);
    }

    /**
     * Register hourly archived/logically-deleted flag sync.
     */
    private function registerArchivedFlagsSchedule(): void
    {
        // HOURLY: Archived/logically-deleted flag sync — targeted bulk update
        Schedule::job(new SyncArchivedStockItemFlagsJob())
            ->name('sync-archived-stock-item-flags')
            ->hourly()->onOneServer()->withoutOverlapping(10);
    }

    /**
     * Register Linnworks order sync schedules (multi-tier redundancy).
     */
    private function registerOrderSchedules(): void
    {
        $this->registerOrderCursorSchedule();
        $this->registerOrderTierSchedules();
        $this->registerOpenOrdersSchedule();
    }

    /**
     * Register the minute-level cursor order sync.
     */
    private function registerOrderCursorSchedule(): void
    {
        // EVERY MINUTE: Cursor-based incremental order sync
        Schedule::job(new SyncLinnworksOrdersByCursorJob())
            ->name('sync-linnworks-orders-cursor')
            ->everyMinute()->onOneServer()->withoutOverlapping(2);
    }

    /**
     * Register hourly/daily/weekly/monthly order sync tiers.
     */
    private function registerOrderTierSchedules(): void
    {
        Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Hourly))
            ->name('sync-linnworks-orders-hourly')
            ->hourly()->onOneServer()->withoutOverlapping(15);

        Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Daily))
            ->name('sync-linnworks-orders-daily')
            ->daily()->onOneServer()->withoutOverlapping(30);

        Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Weekly))
            ->name('sync-linnworks-orders-weekly')
            ->weekly()->onOneServer()->withoutOverlapping(60);

        // WEEKLY (offset Wednesday): Widest safety net — 28 day lookback
        Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Monthly))
            ->name('sync-linnworks-orders-monthly')
            ->weeklyOn(3)->onOneServer()->withoutOverlapping(60);
    }

    /**
     * Register hourly open orders backup sync.
     */
    private function registerOpenOrdersSchedule(): void
    {
        // HOURLY: Backup sync for all open orders via SQL API + v2 REST fetch
        Schedule::job(new SyncAllOpenLinnworksOrdersJob())
            ->name('sync-all-open-linnworks-orders')
            ->hourly()->onOneServer()->withoutOverlapping(5);
    }

    /**
     * Register purchase order sync schedules.
     */
    private function registerPurchaseOrderSchedules(): void
    {
        // EVERY 5 MIN: Fast PO sync — OPEN/PENDING/PARTIAL (6mo) + DELIVERED today
        Schedule::job(new SyncFastPurchaseOrdersJob())
            ->name('sync-fast-purchase-orders')
            ->everyFiveMinutes()->onOneServer()->withoutOverlapping(5);

        // DAILY: Normal PO sync — last 7 days, dates calculated at execution time
        Schedule::call(static function (): void {
            SyncPurchaseOrdersByDateRangeJob::dispatch(
                \now()->subDays(7)->startOfDay()->toDateTimeImmutable(),
                \now()->toDateTimeImmutable(),
            );
        })->name('sync-purchase-orders-daily')->daily()->onOneServer()->withoutOverlapping(30);

        // QUARTERLY: Full PO backfill — all POs, all statuses, safety net
        Schedule::job(new SyncAllPurchaseOrdersJob())
            ->name('sync-all-purchase-orders-quarterly')
            ->quarterly()->onOneServer()->withoutOverlapping(300);
    }
}
