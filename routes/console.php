<?php

declare(strict_types=1);

use App\Presentation\Jobs\ProcessProductSearchFeedJob;
use App\Presentation\Jobs\SyncBingAdsToMixpanelJob;
use App\Presentation\Jobs\SyncCampaignLookupTableJob;
use App\Presentation\Jobs\SyncGoogleAdsToMixpanelJob;
use App\Presentation\Jobs\SyncLinnworksStockItemsJob;
use App\Presentation\Jobs\SyncOrdersToMixpanelJob;
use App\Presentation\Jobs\SyncShopwiredCustomersJob;
use App\Presentation\Jobs\SyncShopwiredOrdersJob;
use Illuminate\Support\Facades\Schedule;

// Campaign lookup table sync - runs BEFORE ad spend sync (7:55 AM UTC)
Schedule::job(new SyncCampaignLookupTableJob())
    ->twiceDaily(first: 7, second: 19)
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(10);

// ============================================================================
// Ad Spend Sync: 3-tier resilience strategy
// All use Schedule::call() to calculate dates at execution time (Octane-safe)
// ============================================================================

// DAILY: Yesterday only (operational visibility)
// Runs at 08:00 UK time so dashboards have fresh data when team starts work
Schedule::call(static function (): void {
    $yesterday = new DateTimeImmutable('yesterday');
    SyncGoogleAdsToMixpanelJob::dispatch($yesterday, $yesterday);
})
    ->name('sync-google-ads-daily')
    ->dailyAt('08:00')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(10);

// WEEKLY: Last 14 days (catch-up with 1 failure tolerance)
// 2-week window means 1 weekly failure can be tolerated before data gaps
Schedule::call(static function (): void {
    $to = new DateTimeImmutable('yesterday');
    $from = new DateTimeImmutable('-15 days'); // 14 days back from yesterday
    SyncGoogleAdsToMixpanelJob::dispatch($from, $to);
})
    ->name('sync-google-ads-weekly')
    ->weeklyOn(0, '06:00') // 0 = Sunday
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(60);

// MONTHLY: Previous 2 calendar months (ultimate safety net)
// Covers 2 full months for resilience if weekly jobs fail
Schedule::call(static function (): void {
    $from = new DateTimeImmutable('first day of -2 months');
    $to = new DateTimeImmutable('last day of previous month');
    SyncGoogleAdsToMixpanelJob::dispatch($from, $to);
})
    ->name('sync-google-ads-monthly')
    ->lastDayOfMonth('04:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(120);

// ============================================================================
// Bing Ads Sync: 3-tier resilience strategy (same pattern as Google Ads)
// Staggered 5-30 min after Google to spread API load
// ============================================================================

// DAILY: Yesterday only (operational visibility)
Schedule::call(static function (): void {
    $yesterday = new DateTimeImmutable('yesterday');
    SyncBingAdsToMixpanelJob::dispatch($yesterday, $yesterday);
})
    ->name('sync-bing-ads-daily')
    ->dailyAt('08:05')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(15); // Extended for Bing async reporting

// WEEKLY: Last 14 days (catch-up with 1 failure tolerance)
Schedule::call(static function (): void {
    $to = new DateTimeImmutable('yesterday');
    $from = new DateTimeImmutable('-15 days');
    SyncBingAdsToMixpanelJob::dispatch($from, $to);
})
    ->name('sync-bing-ads-weekly')
    ->weeklyOn(0, '06:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(90); // Extended for Bing async reporting

// MONTHLY: Previous 2 calendar months (ultimate safety net)
Schedule::call(static function (): void {
    $from = new DateTimeImmutable('first day of -2 months');
    $to = new DateTimeImmutable('last day of previous month');
    SyncBingAdsToMixpanelJob::dispatch($from, $to);
})
    ->name('sync-bing-ads-monthly')
    ->lastDayOfMonth('04:30')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(180); // Extended for Bing async reporting (2 months of data)

// DooFinder product search feed - daily at 1:00 AM UK time
// Fetches source feed, transforms titles (<title> ← <d_title>), uploads to S3
Schedule::job(new ProcessProductSearchFeedJob())
    ->dailyAt('01:00')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(30);

// ============================================================================
// ShopWired Customer Sync: Daily full refresh
// Syncs all ~60k customers from ShopWired API to local PostgreSQL
// Unlike orders (date-range filtered), customers require full-sync approach
// ============================================================================

// DAILY: Full customer sync at 5am UK time
// At 60 req/min rate limit, ~68k customers takes ~45-50 minutes
// Daily ensures new customers are captured quickly
Schedule::job(new SyncShopwiredCustomersJob())
    ->name('sync-shopwired-customers-daily')
    ->dailyAt('05:00')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(60); // 60 min lock - job runs ~45-50 min

// HOURLY: Quick sync of recent customers (5 pages each type, ~1000 customers)
Schedule::job(new SyncShopwiredCustomersJob(maxTradePages: 5, maxNonTradePages: 5))
    ->name('sync-shopwired-customers-hourly')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(5);

// EVERY 5 MIN: Micro sync (1 page each type, ~200 customers, ~30s)
// Keeps customer data near real-time for order processing
Schedule::job(new SyncShopwiredCustomersJob(maxTradePages: 1, maxNonTradePages: 1))
    ->name('sync-shopwired-customers-micro')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(2);

// ============================================================================
// ShopWired Order Sync (Generator-based): 3-tier resilience strategy
// Memory-efficient pagination from newest → oldest using generators
// Complements the date-range sync above for different use cases
// ============================================================================

// DAILY: Full order sync at 4:00 AM UK time
// Iterates all orders (newest first) until reaching already-synced orders
Schedule::job(new SyncShopwiredOrdersJob())
    ->name('sync-shopwired-orders-daily')
    ->dailyAt('04:00')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(60);

// HOURLY: Quick sync (5 pages, ~500 orders)
Schedule::job(new SyncShopwiredOrdersJob(maxPages: 5))
    ->name('sync-shopwired-orders-hourly')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(5);

// EVERY 5 MIN: Micro sync (1 page, ~100 orders)
// Keeps order data near real-time
Schedule::job(new SyncShopwiredOrdersJob(maxPages: 1))
    ->name('sync-shopwired-orders-micro')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(2);

// ============================================================================
// Mixpanel Order Sync: Nightly backend sync for orders missed by frontend
// Catches orders not tracked by JS SDK (ad blockers, JS errors, page abandonment)
// Uses 3-tier resilience: nightly (operational), weekly (catch-up)
// ============================================================================

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

// ============================================================================
// Linnworks Stock Item Sync: Frequent refresh
// Syncs ~4k stock items with extended properties from Linnworks to PostgreSQL
// Used for inventory lookups and order enrichment
// Fast endpoint (~1 min for full sync), no practical rate limit concerns
// ============================================================================

// EVERY 10 MIN: Full stock item sync
// Keeps inventory data near real-time for order processing and lookups
Schedule::job(new SyncLinnworksStockItemsJob())
    ->name('sync-linnworks-stock-items')
    ->everyTenMinutes()
    ->onOneServer()
    ->withoutOverlapping(5); // 5 min lock - job runs ~1 min
