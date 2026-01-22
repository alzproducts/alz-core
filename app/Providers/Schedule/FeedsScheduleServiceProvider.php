<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Presentation\Jobs\Feeds\ProcessProductSearchFeedJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Product Feeds Schedule Definitions
 *
 * Handles scheduled generation and upload of product search feeds.
 */
final class FeedsScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        // DooFinder product search feed - daily at 1:00 AM UK time
        // Fetches source feed, transforms titles (<title> ← <d_title>), uploads to S3
        Schedule::job(new ProcessProductSearchFeedJob())
            ->dailyAt('01:00')
            ->timezone('Europe/London')
            ->onOneServer()
            ->withoutOverlapping(30);
    }
}
