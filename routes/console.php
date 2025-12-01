<?php

declare(strict_types=1);

use App\Presentation\Jobs\SyncCampaignLookupTableJob;
use App\Presentation\Jobs\SyncGoogleAdsToMixpanelJob;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function (): void {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Campaign lookup table sync - runs BEFORE ad spend sync (7:55 AM UTC)
Schedule::job(new SyncCampaignLookupTableJob())
    ->dailyAt('07:55')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(10)
    ->skip(static fn(): bool => (bool) config('services.ad_spend_sync.enabled', true) === false);

// Ad spend data sync - runs AFTER lookup table is updated (8:00 AM UTC)
// Date is calculated at job execution time, not at boot time (prevents stale date issues)
Schedule::job(new SyncGoogleAdsToMixpanelJob())
    ->dailyAt('08:00')
    ->timezone('UTC')
    ->onOneServer()
    ->withoutOverlapping(10)
    ->skip(static fn(): bool => (bool) config('services.ad_spend_sync.enabled', true) === false);
