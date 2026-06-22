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
        $realBefore = \memory_get_usage(true);
        $heapBefore = \memory_get_usage(false);

        try {
            $next($job);
        } finally {
            $this->logMemoryUsage($job, $realBefore, $heapBefore);
        }
    }

    private function logMemoryUsage(object $job, int $realBefore, int $heapBefore): void
    {
        $realAfter = \memory_get_usage(true);
        $heapAfter = \memory_get_usage(false);
        $realDelta = $realAfter - $realBefore;
        $heapDelta = $heapAfter - $heapBefore;

        Log::info('Job memory snapshot', [
            'job' => $job::class,
            'memory_before_mb' => self::toMb($realBefore),
            'memory_after_mb' => self::toMb($realAfter),
            'memory_delta_mb' => self::toMb($realDelta),
            'memory_peak_mb' => self::toMb(\memory_get_peak_usage(true)),
            'heap_before_mb' => self::toMb($heapBefore),
            'heap_after_mb' => self::toMb($heapAfter),
            'heap_delta_mb' => self::toMb($heapDelta),
        ]);

        $this->logThresholdWarning($job, $heapDelta, $realDelta);
    }

    private function logThresholdWarning(object $job, int $heapDelta, int $realDelta): void
    {
        if ($this->threshold > 0 && $heapDelta > $this->threshold) {
            Log::warning('Job memory growth exceeded threshold', [
                'job' => $job::class,
                'heap_delta_mb' => self::toMb($heapDelta),
                'delta_mb' => self::toMb($realDelta),
                'threshold_mb' => self::toMb($this->threshold),
            ]);
        }
    }

    private static function toMb(int $bytes): float
    {
        return \round($bytes / 1_048_576, 2);
    }
}
