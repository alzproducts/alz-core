<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Log;

final class MemoryTrackingMiddleware
{
    private readonly int $threshold;

    public function __construct()
    {
        $this->threshold = \config()->integer('queue.memory_leak_threshold_bytes', 2 * 1024 * 1024);
    }

    public function handle(object $job, Closure $next): void
    {
        $before = \memory_get_usage(true);

        try {
            $next($job);
        } finally {
            $this->logMemoryUsage($job, $before);
        }
    }

    private function logMemoryUsage(object $job, int $before): void
    {
        $after = \memory_get_usage(true);
        $delta = $after - $before;

        Log::info('Job memory snapshot', [
            'job' => $job::class,
            'memory_before_mb' => self::toMb($before),
            'memory_after_mb' => self::toMb($after),
            'memory_delta_mb' => self::toMb($delta),
            'memory_peak_mb' => self::toMb(\memory_get_peak_usage(true)),
        ]);

        $this->logThresholdWarning($job, $delta);
    }

    private function logThresholdWarning(object $job, int $delta): void
    {
        if ($this->threshold > 0 && $delta > $this->threshold) {
            Log::warning('Job memory growth exceeded threshold', [
                'job' => $job::class,
                'delta_mb' => self::toMb($delta),
                'threshold_mb' => self::toMb($this->threshold),
            ]);
        }
    }

    private static function toMb(int $bytes): float
    {
        return \round($bytes / 1_048_576, 2);
    }
}
