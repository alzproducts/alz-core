<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Contracts\Queue\Job;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\ServiceProvider;

use function Sentry\captureException;

use Sentry\SentrySdk;
use WeakMap;

/**
 * Centralised queue observability hooks.
 *
 * - Queue::before — logs job start with queue/connection/attempt context
 * - Queue::after — logs job completion with duration and, on the first attempt, queue wait
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
        // Local, not a static property: a worker process handles many jobs.
        /** @var WeakMap<Job, float> $startedAt */
        $startedAt = new WeakMap();

        Queue::before(static function (JobProcessing $event) use ($startedAt): void {
            $startedAt[$event->job] = \microtime(true);

            Log::info('Job starting', self::context($event->job, $event->connectionName) + ['attempt' => $event->job->attempts()]);
        });

        Queue::after(static function (JobProcessed $event) use ($startedAt): void {
            Log::info('Job completed', self::context($event->job, $event->connectionName) + self::timings($event->job, $startedAt[$event->job] ?? null));
        });
    }

    /**
     * @return array<string, string|null>
     */
    private static function context(Job $job, string $connection): array
    {
        return ['job' => $job->resolveName(), 'queue' => $job->getQueue(), 'connection' => $connection];
    }

    /**
     * @return array<string, int>
     */
    private static function timings(Job $job, ?float $startedAt): array
    {
        if ($startedAt === null) {
            return [];
        }

        $timings = ['duration_ms' => (int) \round((\microtime(true) - $startedAt) * 1000)];

        // pushedAt is set once at push time, so only attempt 1 measures pure queue wait.
        $pushedAt = $job->payload()['pushedAt'] ?? null;

        if ($job->attempts() === 1 && \is_numeric($pushedAt)) {
            $timings['queue_wait_ms'] = \max(0, (int) \round(($startedAt - (float) $pushedAt) * 1000));
        }

        return $timings;
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
