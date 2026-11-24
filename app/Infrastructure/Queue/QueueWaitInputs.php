<?php

declare(strict_types=1);

namespace App\Infrastructure\Queue;

/**
 * The inputs of Horizon's wait estimate for one queue (ready jobs × mean runtime ÷ processes).
 */
final readonly class QueueWaitInputs
{
    public function __construct(
        public ?int $readyJobs,
        public ?float $meanRuntimeMs,
        public ?int $workerProcesses,
    ) {}

    public static function unavailable(): self
    {
        return new self(null, null, null);
    }

    /**
     * @return array<string, float|int>
     */
    public function toContext(): array
    {
        return \array_filter([
            'ready_jobs' => $this->readyJobs,
            'mean_runtime_ms' => $this->meanRuntimeMs,
            'worker_processes' => $this->workerProcesses,
        ], static fn(float|int|null $value): bool => $value !== null);
    }
}
