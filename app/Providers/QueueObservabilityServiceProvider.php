<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

/**
 * Centralised queue observability hooks.
 *
 * Registers Queue::before for job start logging. Provides a single
 * source of truth for job lifecycle logging without modifying
 * individual job classes.
 *
 * Queue::failing is intentionally omitted — jobs already have
 * dedicated failed() methods with specific logging and notifications.
 * A global failing hook would cause duplicate logging.
 */
final class QueueObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Queue::before(static function (JobProcessing $event): void {
            Log::info('Job starting', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempt' => $event->job->attempts(),
            ]);
        });
    }
}
