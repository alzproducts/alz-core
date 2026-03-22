<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Inventory;

use App\Application\Inventory\UseCases\SyncFullStockToShopwiredUseCase;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
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
 * Scheduled job: full Linnworks → ShopWired stock sync.
 *
 * Fetches all stock from Linnworks, compares against the local ShopWired DB
 * snapshot, and pushes any differences. Acts as a safety net to catch drift
 * that the delta sync may miss (e.g., order lock/unlock changes).
 *
 * @see InventoryScheduleServiceProvider for schedule frequency.
 *
 * @see SyncFullStockToShopwiredUseCase
 */
final class SyncFullStockToShopwiredJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;
    public int $maxExceptions = 2;

    /** @var array<int> */
    public array $backoff = [60];

    public int $timeout = 90;
    public bool $failOnTimeout = true;

    /**
     * Unique for 10 minutes — matches the schedule frequency.
     */
    public int $uniqueFor = 600;

    public function uniqueId(): string
    {
        return 'sync-full-stock-to-shopwired';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
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
    public function handle(SyncFullStockToShopwiredUseCase $useCase): void
    {
        $useCase->execute();
    }
}
