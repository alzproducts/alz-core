<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\Catalog\SyncCreditProductPopularityRankingSnapshotJob;
use App\Infrastructure\Jobs\Catalog\SyncProductPopularityRankingSnapshotJob;
use App\Infrastructure\Jobs\Catalog\SyncSkuPopularityRankingSnapshotJob;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Popularity Snapshot Schedule Definitions
 *
 * Weekly write-path snapshots for popularity ranking pipelines.
 * All run Sunday 03:00 Europe/London during the quietest traffic period;
 * downstream consumer schedules (sort orders, best-sellers, labels) read
 * the resulting snapshots later in the morning.
 *
 *   - Product popularity ranking snapshot
 *   - SKU popularity ranking snapshot
 *   - Credit-customer product popularity ranking snapshot
 */
final class PopularitySnapshotScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $this->registerProductPopularityRankingSnapshotSchedule();
        $this->registerSkuPopularityRankingSnapshotSchedule();
        $this->registerCreditProductPopularityRankingSnapshotSchedule();
    }

    /**
     * Weekly product popularity ranking snapshot.
     *
     * Inserts ~2,500 rows (one per catalog product) tagged with
     * `algorithm_version` from the active config row.
     *
     * @throws RuntimeException
     */
    private function registerProductPopularityRankingSnapshotSchedule(): void
    {
        Schedule::job(new SyncProductPopularityRankingSnapshotJob())
            ->name('sync-product-popularity-ranking-snapshot')
            ->weeklyOn(Carbon::SUNDAY, '03:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(60);
    }

    /**
     * Weekly SKU popularity ranking snapshot.
     *
     * One row per catalog SKU tagged with `algorithm_version` from the active
     * config row.
     *
     * @throws RuntimeException
     */
    private function registerSkuPopularityRankingSnapshotSchedule(): void
    {
        Schedule::job(new SyncSkuPopularityRankingSnapshotJob())
            ->name('sync-sku-popularity-ranking-snapshot')
            ->weeklyOn(Carbon::SUNDAY, '03:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(60);
    }

    /**
     * Weekly credit-customer product popularity ranking snapshot.
     *
     * One row per catalog product tagged with `algorithm_version` from the
     * active config row; products without credit sales get credit_tier = NULL.
     *
     * @throws RuntimeException
     */
    private function registerCreditProductPopularityRankingSnapshotSchedule(): void
    {
        Schedule::job(new SyncCreditProductPopularityRankingSnapshotJob())
            ->name('sync-credit-product-popularity-ranking-snapshot')
            ->weeklyOn(Carbon::SUNDAY, '03:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(60);
    }
}
