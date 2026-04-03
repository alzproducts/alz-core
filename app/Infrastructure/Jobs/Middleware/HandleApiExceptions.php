<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Middleware;

use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Closure;
use Illuminate\Support\Facades\Log;

/**
 * Centralised error handling for queue jobs calling external APIs.
 *
 * Handles two API-specific exception types that require behaviour
 * Laravel's Worker cannot provide on its own:
 *
 * - TransientApiFailure with retryAfter: releases with API-provided delay
 * - PermanentApiFailure: fails immediately (no wasted retries)
 *
 * All other exceptions bubble naturally to the Worker, which retries
 * with $backoff/$maxExceptions and eventually fails. Queue::failing
 * handles logging and Sentry capture for all permanent failures.
 */
final class HandleApiExceptions
{
    public function handle(object $job, Closure $next): void
    {
        try {
            $next($job);
        } catch (TransientApiFailure $e) {
            self::releaseOrRethrow($job, $e);
        } catch (PermanentApiFailure $e) {
            $job->fail($e);
        }
    }

    /**
     * Release with API-provided delay, or rethrow for standard backoff.
     *
     * @throws TransientApiFailure When no retryAfter provided (uses Laravel's default backoff)
     */
    private static function releaseOrRethrow(object $job, TransientApiFailure $e): void
    {
        Log::warning('Job transient failure, releasing for retry', [
            'job' => $job::class,
            'service' => $e->serviceName,
            'retry_after' => $e->retryAfter,
        ]);

        if ($e->retryAfter !== null) {
            $job->release($e->retryAfter);

            return;
        }

        throw $e;
    }
}
