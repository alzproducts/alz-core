<?php

declare(strict_types=1);

use App\Presentation\Jobs\ProcessProductSearchFeedJob;
use App\Presentation\Jobs\SyncBingAdsToMixpanelJob;
use App\Presentation\Jobs\SyncCampaignLookupTableJob;
use App\Presentation\Jobs\SyncGoogleAdsToMixpanelJob;
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
// ShopWired Order Sync: Hourly with 2-hour overlap
// Syncs orders from ShopWired API to local PostgreSQL for fast queries
// ============================================================================

// HOURLY: 2-hour overlap window ensures no orders missed at boundaries
Schedule::call(static function (): void {
    SyncShopwiredOrdersJob::dispatch(
        from: new DateTimeImmutable('-2 hours'),
        to: new DateTimeImmutable('now'),
    );
})
    ->name('sync-shopwired-orders-hourly')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(15);

// ============================================================================
// ShopWired Customer Sync: Daily full refresh
// Syncs all ~60k customers from ShopWired API to local PostgreSQL
// Unlike orders (date-range filtered), customers require full-sync approach
// ============================================================================

// DAILY: Full customer sync at 5am UK time
// At 60 req/min rate limit, ~68k customers takes ~15-20 minutes
// Daily ensures new customers are captured quickly
Schedule::job(new SyncShopwiredCustomersJob())
    ->dailyAt('05:00')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(30); // 30 min lock - job runs ~15-20 min
