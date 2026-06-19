<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncArchivedStockItemFlagsUseCase;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Asynchronously sync is_archived and is_logically_deleted flags from Linnworks.
 *
 * Fetches only flagged items via ExecuteCustomScriptQuery and performs targeted
 * bulk updates. Designed for hourly execution.
 */
final class SyncArchivedStockItemFlagsJob extends AbstractJob implements ShouldBeUnique
{
    /**
     * Maximum number of attempts before giving up.
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
    public array $backoff = [60];

    /**
     * Job timeout in seconds.
     *
     * Expected runtime is well under a minute for a small flagged-item set.
     */
    public int $timeout = 300;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 3600;

    public function uniqueId(): string
    {
        return 'sync-archived-stock-item-flags';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
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
        return \now()->addHours(2)->toDateTimeImmutable();
    }

    /**
     * Execute the job.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function handle(SyncArchivedStockItemFlagsUseCase $useCase): void
    {
        $useCase->execute();
    }
}
