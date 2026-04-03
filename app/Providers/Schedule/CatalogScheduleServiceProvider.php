<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\Catalog\SyncRatingFiltersJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Catalog Schedule Definitions
 *
 * Hourly sync mapping product review ratings to ShopWired product filters.
 */
final class CatalogScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $this->registerRatingFilterSchedule();
    }

    /**
     * Independent hourly sync: maps product ratings to ShopWired filter values.
     *
     * Reads from reviews_io.product_ratings (populated by ReviewsIo Stage 1) but runs
     * independently — catches up on any filter drift each hour.
     *
     * @throws RuntimeException
     */
    private function registerRatingFilterSchedule(): void
    {
        Schedule::job(new SyncRatingFiltersJob())
            ->name('sync-rating-filters')
            ->hourly()
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }
}
