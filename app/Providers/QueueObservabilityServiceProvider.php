<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

use function Sentry\captureException;

use Sentry\SentrySdk;

/**
 * Centralised queue observability hooks.
 *
 * - Queue::before — logs job start with queue/connection/attempt context
 * - Queue::after — logs job completion (centralised default for all jobs)
 * - Queue::failing — logs permanent failure and captures in Sentry
 */
final class QueueObservabilityServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->registerJobLogging();
        $this->registerFailureCapture();
    }

    private function registerJobLogging(): void
    {
        Queue::before(static function (JobProcessing $event): void {
            Log::info('Job starting', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'attempt' => $event->job->attempts(),
            ]);
        });

        Queue::after(static function (JobProcessed $event): void {
            Log::info('Job completed', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
            ]);
        });
    }

    private function registerFailureCapture(): void
    {
        Queue::failing(static function (JobFailed $event): void {
            Log::critical('Job failed permanently', [
                'job' => $event->job->resolveName(),
                'queue' => $event->job->getQueue(),
                'connection' => $event->connectionName,
                'exception' => $event->exception::class,
                'message' => $event->exception->getMessage(),
            ]);

            if (\class_exists(SentrySdk::class)) {
                captureException($event->exception);
            }
        });
    }
}
