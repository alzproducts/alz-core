<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Inventory;

use App\Application\Inventory\UseCases\SyncDeltaStockToShopwiredUseCase;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Scheduled job: delta Linnworks → ShopWired stock sync.
 *
 * Queries Linnworks for SKUs changed since the last cursor and pushes only
 * the differences to ShopWired. Fast, incremental path for near-real-time
 * stock accuracy. The full sync job handles any drift this misses.
 *
 * @see SyncDeltaStockToShopwiredUseCase
 * @see InventoryScheduleServiceProvider for schedule frequency.
 */
final class SyncDeltaStockToShopwiredJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 4;
    public int $maxExceptions = 2;

    /** @var array<int> */
    public array $backoff = [30];

    public int $timeout = 60;

    /**
     * Unique for 5 minutes — matches the schedule frequency.
     */
    public int $uniqueFor = 300;

    public function uniqueId(): string
    {
        return 'sync-delta-stock-to-shopwired';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            ServiceCircuitBreaker::linnworks(),
            ServiceCircuitBreaker::shopwired(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws LockAcquisitionException
     */
    public function handle(SyncDeltaStockToShopwiredUseCase $useCase): void
    {
        $useCase->execute();
    }
}
