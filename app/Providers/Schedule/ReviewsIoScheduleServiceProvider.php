<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\ReviewsIo\SyncProductRatingsJob;
use App\Infrastructure\Jobs\ReviewsIo\UpdateShopwiredRatingsJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Reviews.io Schedule Definitions
 *
 * Two-stage pipeline for syncing product ratings, every 4 hours:
 * - Stage 1 (:00): Fetch ratings from Reviews.io API → local database
 * - Stage 2 (:30): Push aggregated ratings to ShopWired custom fields
 *
 * The 30-minute gap between stages ensures:
 * 1. Stage 1 has time to complete (typically 2-5 min for ~500 SKUs)
 * 2. Buffer for retries if Reviews.io API is temporarily unavailable
 * 3. Stage 2 reads fresh data from reviews_io.product_ratings
 */
final class ReviewsIoScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $this->registerRatingsSyncSchedule();
    }

    /**
     * @throws RuntimeException
     */
    private function registerRatingsSyncSchedule(): void
    {
        // Stage 1: Fetch ratings from Reviews.io API and store locally
        Schedule::job(new SyncProductRatingsJob())
            ->name('reviews-io-sync-ratings-stage-1')
            ->cron('0 */4 * * *')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(15);

        // Stage 2: Push aggregated ratings to ShopWired custom fields
        Schedule::job(new UpdateShopwiredRatingsJob())
            ->name('reviews-io-sync-ratings-stage-2')
            ->cron('30 */4 * * *')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }
}
