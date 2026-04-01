<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\Mixpanel\SyncBingAdsToMixpanelJob;
use App\Infrastructure\Jobs\Mixpanel\SyncCampaignLookupTableJob;
use App\Infrastructure\Jobs\Mixpanel\SyncGoogleAdsToMixpanelJob;
use DateTimeImmutable;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Advertising Platforms Schedule Definitions
 *
 * Syncs ad spend data from Google Ads and Bing Ads to Mixpanel.
 * Uses 3-tier resilience strategy: daily (operational), weekly (catch-up), monthly (safety net).
 *
 * All schedules use Schedule::call() to calculate dates at execution time (Octane-safe).
 */
final class AdsScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        $this->registerCampaignLookupSchedule();
        $this->registerGoogleAdsSchedules();
        $this->registerBingAdsSchedules();
    }

    /**
     * Campaign lookup table must sync BEFORE ad spend sync.
     *
     * @throws RuntimeException
     */
    private function registerCampaignLookupSchedule(): void
    {
        Schedule::job(new SyncCampaignLookupTableJob())
            ->twiceDaily(first: 7, second: 19)
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(10);
    }

    /**
     * Google Ads: 3-tier resilience strategy.
     *
     * @throws RuntimeException
     */
    private function registerGoogleAdsSchedules(): void
    {
        $this->scheduleGoogleAdsDailySync();
        $this->scheduleGoogleAdsWeeklySync();
        $this->scheduleGoogleAdsMonthlySync();
    }

    /** @throws RuntimeException */
    private function scheduleGoogleAdsDailySync(): void
    {
        Schedule::call(static function (): void {
            $yesterday = new DateTimeImmutable('yesterday');
            SyncGoogleAdsToMixpanelJob::dispatch($yesterday, $yesterday);
        })
            ->name('sync-google-ads-daily')
            ->dailyAt('08:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(10);
    }

    /** @throws RuntimeException */
    private function scheduleGoogleAdsWeeklySync(): void
    {
        Schedule::call(static function (): void {
            $to = new DateTimeImmutable('yesterday');
            $from = new DateTimeImmutable('-15 days');
            SyncGoogleAdsToMixpanelJob::dispatch($from, $to);
        })
            ->name('sync-google-ads-weekly')
            ->weeklyOn(0, '06:00')
            ->timezone('UTC')
            ->onOneServer()
            ->withoutOverlapping(60);
    }

    /** @throws RuntimeException */
    private function scheduleGoogleAdsMonthlySync(): void
    {
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
    }

    /**
     * @throws RuntimeException
     */
    private function registerBingAdsSchedules(): void
    {
        $this->scheduleBingAdsDailySync();
        $this->scheduleBingAdsWeeklySync();
        $this->scheduleBingAdsMonthlySync();
    }

    /** @throws RuntimeException */
    private function scheduleBingAdsDailySync(): void
    {
        Schedule::call(static function (): void {
            $yesterday = new DateTimeImmutable('yesterday');
            SyncBingAdsToMixpanelJob::dispatch($yesterday, $yesterday);
        })
            ->name('sync-bing-ads-daily')
            ->dailyAt('08:05')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(15);
    }

    /** @throws RuntimeException */
    private function scheduleBingAdsWeeklySync(): void
    {
        Schedule::call(static function (): void {
            $to = new DateTimeImmutable('yesterday');
            $from = new DateTimeImmutable('-15 days');
            SyncBingAdsToMixpanelJob::dispatch($from, $to);
        })
            ->name('sync-bing-ads-weekly')
            ->weeklyOn(0, '06:30')
            ->timezone('UTC')
            ->onOneServer()
            ->withoutOverlapping(90);
    }

    /** @throws RuntimeException */
    private function scheduleBingAdsMonthlySync(): void
    {
        Schedule::call(static function (): void {
            $from = new DateTimeImmutable('first day of -2 months');
            $to = new DateTimeImmutable('last day of previous month');
            SyncBingAdsToMixpanelJob::dispatch($from, $to);
        })
            ->name('sync-bing-ads-monthly')
            ->lastDayOfMonth('04:30')
            ->timezone('UTC')
            ->onOneServer()
            ->withoutOverlapping(180);
    }
}
