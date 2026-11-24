<?php

declare(strict_types=1);

namespace Tests\Feature\Concerns;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SyncQueue;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;
use Illuminate\Queue\WorkerOptions;
use Override;
use ReflectionMethod;
use RuntimeException;
use Throwable;

/**
 * Runs a job's payload through the framework's worker, under a supervisor that would try it three times.
 *
 * @property Application $app
 */
trait RunsQueuedJobs
{
    /**
     * @param int $delivery 2 for a payload the queue has handed out before
     */
    private function work(ShouldQueue $job, int $delivery = 1): SyncJob
    {
        $queued = $this->queued($job, $delivery);

        try {
            $this->worker()->process('redis-long', $queued, new WorkerOptions(maxTries: 3));
        } catch (Throwable $e) {
            if (!$queued->hasFailed()) {
                throw $e;
            }
        }

        return $queued;
    }

    /**
     * Runs the worker's own timeout steps, without the signal and the kill around them.
     */
    private function timeOut(ShouldQueue $job): SyncJob
    {
        $queued = $this->queued($job, 1);
        $failure = TimeoutExceededException::forJob($queued);

        new ReflectionMethod(Worker::class, 'markJobAsFailedIfWillExceedMaxAttempts')->invoke($this->worker(), 'redis-long', $queued, 3, $failure);
        if (!$queued->hasFailed()) {
            throw new RuntimeException('The timed-out job would run again');
        }

        return $queued;
    }

    private function worker(): Worker
    {
        return $this->app->make('queue.worker');
    }

    private function queued(ShouldQueue $job, int $delivery): SyncJob
    {
        $queue = new class extends SyncQueue {
            public function payloadOf(object $job): string
            {
                return $this->createPayload($job, 'low');
            }
        };
        $queue->setContainer($this->app);

        $queued = new class ($this->app, $queue->payloadOf($job), 'redis-long', 'low') extends SyncJob {
            public int $delivery = 1;

            #[Override]
            public function attempts(): int
            {
                return $this->delivery;
            }
        };
        $queued->delivery = $delivery;

        return $queued;
    }
}
