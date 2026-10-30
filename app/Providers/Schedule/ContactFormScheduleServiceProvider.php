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
        // Releases expired leases and re-dispatches overdue pending actions (ADR-0012)
        Schedule::job(new CleanupStaleContactActionsJob())
            ->name('cleanup-stale-contact-actions')
            ->everyFiveMinutes()
            ->onOneServer()
            ->withoutOverlapping(5); // 5 min lock - job timeout is 2 min
    }
}
