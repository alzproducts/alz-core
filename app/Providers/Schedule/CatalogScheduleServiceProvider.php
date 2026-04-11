<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\Catalog\SyncOffersFiltersJob;
use App\Infrastructure\Jobs\Catalog\SyncRatingFiltersJob;
use App\Infrastructure\Jobs\Catalog\SyncShippingOffersFiltersJob;
use App\Infrastructure\Jobs\Catalog\SyncShippingOptionsFiltersJob;
use App\Infrastructure\Jobs\Catalog\SyncVatReliefFiltersJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Catalog Schedule Definitions
 *
 * Product-level state syncs to ShopWired product filters (three hourly + one 10-minute stock-driven):
 *   - Customer rating filter (from reviews_io.product_ratings)
 *   - VAT relief filter (from shopwired.products.vat_relief)
 *   - Offers → On Sale filter (derived from pricing state + variant inheritance)
 *   - Shipping Offers filter (from shopwired.products.custom_fields->>'free_delivery')
 *   - Shipping Options filter (from shopwired product + variation stock; 10-min cadence, offset +5 min from the upstream stock sync)
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
        $this->registerShippingOffersFilterSchedule();
        $this->registerShippingOptionsFilterSchedule();
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

    /**
     * Hourly sync: maps `shopwired.products.custom_fields->>'free_delivery'` to the
     * ShopWired "Shipping Offers" product filter (optionNo 20).
     *
     * @throws RuntimeException
     */
    private function registerShippingOffersFilterSchedule(): void
    {
        Schedule::job(new SyncShippingOffersFiltersJob())
            ->name('sync-shipping-offers-filters')
            ->hourly()
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }

    /**
     * 10-minute sync: maps shopwired product + variation stock availability to the
     * ShopWired "Shipping Options" product filter (optionNo 25).
     *
     * Stock columns (shopwired.products.stock + shopwired.product_variations.stock) are
     * Linnworks-mirrored copies written by SyncFullStockToShopwiredJob every 10 minutes
     * aligned to HH:00. This job is offset to HH:05 to read freshly-mirrored data.
     *
     * Grace = 10 matches the cadence (mirrors SyncFullStockToShopwiredJob pattern).
     *
     * @throws RuntimeException
     */
    private function registerShippingOptionsFilterSchedule(): void
    {
        Schedule::job(new SyncShippingOptionsFiltersJob())
            ->name('sync-shipping-options-filters')
            // HH:05, HH:15, HH:25, ... — offset 5 min after SyncFullStockToShopwiredJob (HH:00)
            // so we read freshly-mirrored stock rather than racing with the stock writer.
            ->cron('5-59/10 * * * *')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(10);
    }
}
