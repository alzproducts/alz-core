<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\Catalog\SyncBestSellerLabelJob;
use App\Infrastructure\Jobs\Catalog\SyncBestSellersCategoryJob;
use App\Infrastructure\Jobs\Catalog\SyncCreditTierLabelJob;
use App\Infrastructure\Jobs\Catalog\SyncMarginTierLabelJob;
use App\Infrastructure\Jobs\Catalog\SyncOffersFiltersJob;
use App\Infrastructure\Jobs\Catalog\SyncProductSortOrdersJob;
use App\Infrastructure\Jobs\Catalog\SyncProductsViewJob;
use App\Infrastructure\Jobs\Catalog\SyncRatingFiltersJob;
use App\Infrastructure\Jobs\Catalog\SyncRelatedProductsJob;
use App\Infrastructure\Jobs\Catalog\SyncShippingOffersFiltersJob;
use App\Infrastructure\Jobs\Catalog\SyncShippingOptionsFiltersJob;
use App\Infrastructure\Jobs\Catalog\SyncVatReliefFiltersJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Catalog Schedule Definitions
 *
 * Materialized view refresh (every minute):
 *   - catalog.products_view refresh (pre-computes expensive join plan for API reads)
 *
 * Product-level state syncs to ShopWired product filters (three hourly + one 10-minute stock-driven):
 *   - Customer rating filter (from reviews_io.product_ratings)
 *   - VAT relief filter (from shopwired.products.vat_relief)
 *   - Offers → On Sale filter (derived from pricing state + variant inheritance)
 *   - Shipping Offers filter (from shopwired.products.custom_fields->>'free_delivery')
 *   - Shipping Options filter (from shopwired product + variation stock; 10-min cadence, offset +5 min from the upstream stock sync)
 *
 * Popularity-driven consumer syncs (daily, 04:00–05:00 window):
 *   - Product sort orders (04:00, consumes latest snapshot)
 *   - Best Sellers category membership (04:00, top-N from snapshot)
 *   - Best Sellers label (04:15, custom_label_4 list merge)
 *   - Related products custom field (04:30, algorithm SQL + order-sensitive diff)
 *   - Margin-tier label (04:45, custom_label_1 four-tier band)
 *   - Credit-tier label (05:00, custom_label_0 three-tier band, credit-only)
 *
 * Snapshot writers live in {@see PopularitySnapshotScheduleServiceProvider}.
 */
final class CatalogScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $this->registerProductsViewRefreshSchedule();
        $this->registerRatingFilterSchedule();
        $this->registerVatReliefFilterSchedule();
        $this->registerOffersFilterSchedule();
        $this->registerShippingOffersFilterSchedule();
        $this->registerShippingOptionsFilterSchedule();
        $this->registerProductSortOrderSyncSchedule();
        $this->registerBestSellersCategorySchedule();
        $this->registerBestSellerLabelSchedule();
        $this->registerRelatedProductsSyncSchedule();
        $this->registerMarginTierLabelSchedule();
        $this->registerCreditTierLabelSchedule();
    }

    /**
     * Refresh the catalog.products_view materialized view every minute.
     *
     * Pre-computes the expensive join plan (variation aggregation, recursive category
     * traversal) so API reads hit a simple table scan (~5-10ms) instead of 1.1s of joins.
     *
     * @throws RuntimeException
     */
    private function registerProductsViewRefreshSchedule(): void
    {
        Schedule::job(new SyncProductsViewJob())
            ->name('sync-products-view')
            ->everyMinute()
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(2);
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

    /**
     * Daily product sort order sync from popularity snapshot.
     *
     * Runs at 04:00 Europe/London — one hour after the weekly popularity snapshot
     * (Sunday 03:00), so every daily run (including Sunday) consumes the freshest
     * snapshot rather than racing ahead of it.
     *
     * @throws RuntimeException
     */
    private function registerProductSortOrderSyncSchedule(): void
    {
        Schedule::job(new SyncProductSortOrdersJob())
            ->name('sync-product-sort-orders')
            ->dailyAt('04:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }

    /**
     * Daily Best Sellers category sync from popularity ranking.
     *
     * Runs at 04:00 Europe/London — same window as the sort order sync, one hour
     * after the weekly snapshot (Sunday 03:00). On the six non-snapshot days the
     * diff query returns zero rows and the orchestrator exits cleanly, providing
     * drift protection against manual ShopWired admin edits mid-week.
     *
     * @throws RuntimeException
     */
    private function registerBestSellersCategorySchedule(): void
    {
        Schedule::job(new SyncBestSellersCategoryJob())
            ->name('sync-best-sellers-category')
            ->dailyAt('04:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }

    /**
     * Daily Best Sellers label sync from popularity ranking to custom_label_4.
     *
     * Runs at 04:15 Europe/London — 15 minutes after the Best Sellers category sync (04:00),
     * giving the category membership jobs a head start before the label sync dispatches.
     *
     * @throws RuntimeException
     */
    private function registerBestSellerLabelSchedule(): void
    {
        Schedule::job(new SyncBestSellerLabelJob())
            ->name('sync-best-seller-label')
            ->dailyAt('04:15')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }

    /**
     * Daily related products custom field sync.
     *
     * Runs at 04:30 Europe/London — 30 minutes after the Best Sellers sync (04:00),
     * allowing the heavier algorithm SQL (up to 300s timeout) a clean window.
     * Only products whose related list has changed are dispatched.
     *
     * @throws RuntimeException
     */
    private function registerRelatedProductsSyncSchedule(): void
    {
        Schedule::job(new SyncRelatedProductsJob())
            ->name('sync-related-products')
            ->dailyAt('04:30')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(60);
    }

    /**
     * Daily margin-tier label sync to custom_label_1. 04:45 Europe/London,
     * 15 minutes after related products (04:30).
     *
     * @throws RuntimeException
     */
    private function registerMarginTierLabelSchedule(): void
    {
        Schedule::job(new SyncMarginTierLabelJob())
            ->name('sync-margin-tier-label')
            ->dailyAt('04:45')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }

    /**
     * Daily credit-tier label sync to custom_label_0. 05:00 Europe/London,
     * after the margin-tier label sync (04:45). On non-snapshot days the diff
     * returns zero rows and the orchestrator exits cleanly.
     *
     * @throws RuntimeException
     */
    private function registerCreditTierLabelSchedule(): void
    {
        Schedule::job(new SyncCreditTierLabelJob())
            ->name('sync-credit-tier-label')
            ->dailyAt('05:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }
}
