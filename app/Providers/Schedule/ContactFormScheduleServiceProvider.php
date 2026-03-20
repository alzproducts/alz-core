<?php

declare(strict_types=1);

namespace App\Providers\Schedule;

use App\Infrastructure\Jobs\ContactForm\CleanupStaleContactActionsJob;
use Illuminate\Support\Facades\Schedule;
use Illuminate\Support\ServiceProvider;
use RuntimeException;

/**
 * Contact Form Integration Schedule Definitions.
 *
 * Schedules maintenance jobs for contact form submission processing.
 */
final class ContactFormScheduleServiceProvider extends ServiceProvider
{
    /**
     * @throws RuntimeException
     */
    public function boot(): void
    {
        // Cleanup stale 'processing' actions that got stuck (worker crash, network issue, etc.)
        // Resets them to 'pending' and re-dispatches the processing job
        Schedule::job(new CleanupStaleContactActionsJob())
            ->name('cleanup-stale-contact-actions')
            ->hourly()
            ->onOneServer()
            ->withoutOverlapping(5); // 5 min lock - job timeout is 2 min
    }
}
