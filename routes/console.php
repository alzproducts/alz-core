<?php

declare(strict_types=1);

use App\Presentation\Jobs\ProcessProductSearchFeedJob;
use App\Presentation\Jobs\SyncCampaignLookupTableJob;
use App\Presentation\Jobs\SyncGoogleAdsToMixpanelJob;
use Illuminate\Support\Facades\Schedule;

// Campaign lookup table sync - runs BEFORE ad spend sync (7:55 AM UTC)
Schedule::job(new SyncCampaignLookupTableJob())
    ->dailyAt('07:55')
    ->timezone('UTC')
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

// DooFinder product search feed - daily at 1:00 AM UK time
// Fetches source feed, transforms titles (<title> ← <d_title>), uploads to S3
Schedule::job(new ProcessProductSearchFeedJob())
    ->dailyAt('01:00')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(30);
