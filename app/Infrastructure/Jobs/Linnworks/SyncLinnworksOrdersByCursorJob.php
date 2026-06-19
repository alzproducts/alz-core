<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncLinnworksCursorUseCase;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Cursor-based incremental order sync from Linnworks.
 *
 * Runs every minute. Fetches orders modified since the last cursor position.
 * Lightweight — typically processes only a few orders per run.
 */
final class SyncLinnworksOrdersByCursorJob extends AbstractJob implements ShouldBeUnique
{
    /**
     * Maximum number of attempts before giving up.
     *
     * Low retry count since job runs every minute — next scheduled run is implicit retry.
     */
    public int $tries = 4;

    /**
     * Maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 2;
    /**
     * Seconds to wait before retrying.
     *
     * @var array<int>
     */
    public array $backoff = [30];

    /**
     * Job timeout in seconds.
     */
    public int $timeout = 90;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-linnworks-orders-cursor';
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            ServiceCircuitBreaker::linnworks(),
            new HandleApiExceptions(),
        ];
    }

    /**
     * Determine the time at which the job should timeout.
     */
    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function handle(SyncLinnworksCursorUseCase $useCase): void
    {
        $useCase->execute();
    }
}
