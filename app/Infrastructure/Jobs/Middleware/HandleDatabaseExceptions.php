<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Middleware;

use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Infrastructure\AbstractInfrastructureException;
use Closure;

/**
 * Centralised error handling for queue jobs that only access the database.
 *
 * NOT for jobs calling external APIs — use {@see HandleApiExceptions} instead.
 * Using both on the same job would cause catch conflicts.
 *
 * Fails immediately on permanent exceptions that retrying cannot fix:
 * - AbstractInfrastructureException: DatabaseOperationFailedException, DuplicateRecordException
 * - PermanentApiFailure
 *
 * Transient DB conditions — ExternalServiceUnavailableException and RecordNotFoundException
 * (race against concurrent sync transactions) — bubble to the Worker for retry with $backoff.
 */
final class HandleDatabaseExceptions
{
    public function handle(object $job, Closure $next): void
    {
        try {
            $next($job);
        } catch (AbstractInfrastructureException|PermanentApiFailure $e) {
            $job->fail($e);
        }
    }
}
