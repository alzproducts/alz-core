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
use Carbon\Carbon;
use Illuminate\Support\Facades\Schedule;

// ============================================================================
// Helper: Check if currently in ShopWired daily sync window (04:00-07:00 UK)
// Used to skip micro/hourly syncs during full sync to avoid rate limit contention
// ============================================================================
$isDuringShopwiredDailySyncWindow = static function (): bool {
    $ukTime = Carbon::now('Europe/London');
    $hour = $ukTime->hour;

    // Daily syncs run at 04:00 (orders) and 05:30 (customers) UK time
    // Skip micro/hourly during 04:00-06:59 to give full syncs exclusive API access
    return $hour >= 4 && $hour < 7;
};

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

// DAILY: Full customer sync at 5:30am UK time (90 min after orders)
// At 60 req/min rate limit, ~68k customers takes ~45-60 minutes
// Daily ensures new customers are captured quickly
Schedule::job(new SyncShopwiredCustomersJob())
    ->name('sync-shopwired-customers-daily')
    ->dailyAt('05:30')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(75); // 75 min lock - job timeout is 70 min

// HOURLY: Quick sync of recent customers (5 pages each type, ~1000 customers)
// Skipped during daily sync window (see $isDuringShopwiredDailySyncWindow)
Schedule::job(new SyncShopwiredCustomersJob(maxTradePages: 5, maxNonTradePages: 5))
    ->name('sync-shopwired-customers-hourly')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(5)
    ->skip($isDuringShopwiredDailySyncWindow);

// EVERY 5 MIN: Micro sync (1 page each type, ~200 customers, ~30s)
// Keeps customer data near real-time for order processing
// Skipped during daily sync window (see $isDuringShopwiredDailySyncWindow)
Schedule::job(new SyncShopwiredCustomersJob(maxTradePages: 1, maxNonTradePages: 1))
    ->name('sync-shopwired-customers-micro')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(2)
    ->skip($isDuringShopwiredDailySyncWindow);

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
    ->withoutOverlapping(75); // 75 min lock - job timeout is 70 min

// HOURLY: Quick sync (5 pages, ~500 orders)
// Skipped during daily sync window (see $isDuringShopwiredDailySyncWindow)
Schedule::job(new SyncShopwiredOrdersJob(maxPages: 5))
    ->name('sync-shopwired-orders-hourly')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(5)
    ->skip($isDuringShopwiredDailySyncWindow);

// EVERY 5 MIN: Micro sync (1 page, ~100 orders)
// Keeps order data near real-time
// Skipped during daily sync window (see $isDuringShopwiredDailySyncWindow)
Schedule::job(new SyncShopwiredOrdersJob(maxPages: 1))
    ->name('sync-shopwired-orders-micro')
    ->everyFiveMinutes()
    ->onOneServer()
    ->withoutOverlapping(2)
    ->skip($isDuringShopwiredDailySyncWindow);

// ============================================================================
// Mixpanel Order Sync: Nightly backend sync for orders missed by frontend
// Catches orders not tracked by JS SDK (ad blockers, JS errors, page abandonment)
// Uses 3-tier resilience: nightly (operational), weekly (catch-up)
// Uses multi-hash matching to detect orders tracked with different hash variations
// (SHA-256/Base64 algorithms, configured/fallback salts) - see Issue #134
// ============================================================================

// TEMPORARILY DISABLED: Duplicate events bug - see Issue #134
// Frontend and backend generate different hashes for the same order, causing duplicates.
// Re-enable after root cause is fixed.

// // NIGHTLY: 28-hour lookback (24h + 4h buffer for Mixpanel ingestion delay)
// // Runs at 2:00 AM UK time — the extra 4 hours ensures no gaps between runs
// Schedule::call(static function (): void {
//     SyncOrdersToMixpanelJob::dispatch(
//         from: new DateTimeImmutable('-28 hours'),
//         to: new DateTimeImmutable('now'),
//     );
// })
//     ->name('sync-orders-to-mixpanel-nightly')
//     ->dailyAt('02:00')
//     ->timezone('Europe/London')
//     ->onOneServer()
//     ->withoutOverlapping(30);

// // WEEKLY: Last 14 days (safety net with 1 failure tolerance)
// // Deduplication via order_id_hashed + $insert_id prevents duplicates
// Schedule::call(static function (): void {
//     SyncOrdersToMixpanelJob::dispatch(
//         from: new DateTimeImmutable('-14 days'),
//         to: new DateTimeImmutable('now'),
//     );
// })
//     ->name('sync-orders-to-mixpanel-weekly')
//     ->weeklyOn(0, '03:00') // Sunday 3:00 AM
//     ->timezone('Europe/London')
//     ->onOneServer()
//     ->withoutOverlapping(60);

// ============================================================================
// Linnworks Stock Item Sync: Frequent refresh
// Syncs ~4k stock items with extended properties from Linnworks to PostgreSQL
// Used for inventory lookups and order enrichment
// ============================================================================

// EVERY 15 MIN: Full stock item sync
// Keeps inventory data near real-time for order processing and lookups
Schedule::job(new SyncLinnworksStockItemsJob())
    ->name('sync-linnworks-stock-items')
    ->everyFifteenMinutes()
    ->onOneServer()
    ->withoutOverlapping(20); // 20 min lock - job runs 8-12 min in prod
