<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Linnworks\UseCases\SyncStockItemUseCase;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Queue wrapper for syncing a single stock item from Linnworks.
 *
 * Dispatched by SyncStockItemWithCursorUseCase for each recently-modified item.
 * Uniqueness scoped per stockItemId to prevent concurrent syncs of the same item.
 */
final class SyncStockItemJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    /**
     * Maximum number of attempts before giving up.
     */
    public int $tries = 6;

    /**
     * Maximum number of unhandled exceptions to allow before failing.
     */
    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    /**
     * Seconds to wait before retrying.
     *
     * Progressive backoff: 10s, 60s, 10min. Final attempt has a long
     * delay because failure means the item won't update until the daily
     * full sync (up to 24 hours).
     *
     * @var array<int>
     */
    public array $backoff = [10, 60, 600];

    /**
     * Job timeout in seconds.
     *
     * Single item fetch + save should complete well within 30s.
     */
    public int $timeout = 30;

    /**
     * Seconds this job should remain unique.
     */
    public int $uniqueFor = 300;

    public function __construct(
        public readonly Guid $stockItemId,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    /**
     * Get the unique ID for the job.
     */
    public function uniqueId(): string
    {
        return 'sync-stock-item-' . $this->stockItemId->value;
    }

    /**
     * Get the middleware the job should pass through.
     *
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
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
    public function handle(SyncStockItemUseCase $useCase): void
    {
        $useCase->execute($this->stockItemId);
    }
}
