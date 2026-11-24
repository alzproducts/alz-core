<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

use Illuminate\Contracts\Queue\Factory as QueueFactory;
use Illuminate\Support\Facades\Log;
use Laravel\Horizon\Contracts\MetricsRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Laravel\Horizon\RedisQueue;
use Throwable;

/**
 * Reads the inputs behind Horizon's wait estimate for diagnostics.
 *
 * Best effort: a Redis or metrics failure yields {@see QueueWaitInputs::unavailable()}
 * so the caller's alert is never blocked.
 */
final readonly class HorizonQueueWaitInputsReader
{
    public function __construct(
        private QueueFactory $queues,
        private MetricsRepository $metrics,
        private SupervisorRepository $supervisors,
    ) {}

    public function read(string $connection, string $queue): QueueWaitInputs
    {
        try {
            $redis = $this->queues->connection($connection);

            return new QueueWaitInputs(
                readyJobs: $redis instanceof RedisQueue ? $redis->readyNow($queue) : null,
                meanRuntimeMs: $this->metrics->runtimeForQueue($queue),
                workerProcesses: $this->processesFor("{$connection}:{$queue}"),
            );
        } catch (Throwable $e) { // @ignoreException - diagnostics must never block the long wait alert
            Log::warning('Could not read queue metrics for long wait alert', [
                'connection' => $connection,
                'queue' => $queue,
                'exception' => $e->getMessage(),
            ]);

            return QueueWaitInputs::unavailable();
        }
    }

    private function processesFor(string $key): int
    {
        /** @var list<object{processes: array<string, int>}> $supervisors */
        $supervisors = $this->supervisors->all();

        return \array_sum(\array_map(static fn(object $supervisor): int => $supervisor->processes[$key] ?? 0, $supervisors));
    }
}
