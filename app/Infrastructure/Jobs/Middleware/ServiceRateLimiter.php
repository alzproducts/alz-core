<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Middleware;

use App\Providers\RateLimitServiceProvider;
use Illuminate\Queue\Middleware\RateLimited;

/**
 * Proactive rate limiters for outbound API calls from queue jobs.
 *
 * Holds jobs in the queue before execution to prevent exceeding API limits.
 * Rate limiter definitions live in {@see RateLimitServiceProvider}.
 */
final class ServiceRateLimiter
{
    public static function shopwiredApi(): RateLimited
    {
        return new RateLimited('shopwired-api');
    }

    public static function shopwiredApiBulk(): RateLimited
    {
        return new RateLimited('shopwired-api-bulk');
    }
}
