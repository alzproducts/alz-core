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

// DooFinder product search feed - daily at 1:00 AM UK time
// Fetches source feed, transforms titles (<title> ← <d_title>), uploads to S3
Schedule::job(new ProcessProductSearchFeedJob())
    ->dailyAt('01:00')
    ->timezone('Europe/London')
    ->onOneServer()
    ->withoutOverlapping(30);
