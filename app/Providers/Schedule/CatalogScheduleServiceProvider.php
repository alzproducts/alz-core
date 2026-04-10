<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\Catalog\SyncOffersFiltersJob;
use App\Infrastructure\Jobs\Catalog\SyncRatingFiltersJob;
use App\Infrastructure\Jobs\Catalog\SyncVatReliefFiltersJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Catalog Schedule Definitions
 *
 * Hourly syncs mapping product-level state to ShopWired product filters:
 *   - Customer rating filter (from reviews_io.product_ratings)
 *   - VAT relief filter (from shopwired.products.vat_relief)
 *   - Offers → On Sale filter (derived from pricing state + variant inheritance)
 */
final class CatalogScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $this->registerRatingFilterSchedule();
        $this->registerVatReliefFilterSchedule();
        $this->registerOffersFilterSchedule();
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

    /**
     * Hourly sync: maps `shopwired.products.vat_relief` to the ShopWired
     * "Eligible for VAT Relief?" product filter (optionNo 2).
     *
     * Rows with `vat_relief IS NULL` are skipped — the view excludes them.
     *
     * @throws RuntimeException
     */
    private function registerVatReliefFilterSchedule(): void
    {
        Schedule::job(new SyncVatReliefFiltersJob())
            ->name('sync-vat-relief-filters')
            ->hourly()
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }

    /**
     * Hourly sync: maps ShopWired product pricing (parent + variants) to the
     * "Offers → On Sale" filter (optionNo 14).
     *
     * The SQL view is merge-preserving so sibling Offers filter values (e.g.
     * "Free Delivery") survive a dispatch. The first run after deploy will
     * also normalise legacy lowercase `"On sale"` entries to canonical
     * `"On Sale"`.
     *
     * @throws RuntimeException
     */
    private function registerOffersFilterSchedule(): void
    {
        Schedule::job(new SyncOffersFiltersJob())
            ->name('sync-offers-filters')
            ->hourly()
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }
}
