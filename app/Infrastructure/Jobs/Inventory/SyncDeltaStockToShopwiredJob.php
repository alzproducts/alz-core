<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Inventory;

use App\Application\Inventory\UseCases\SyncDeltaStockToShopwiredUseCase;
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
 * Scheduled job: delta Linnworks → ShopWired stock sync.
 *
 * Queries Linnworks for SKUs changed since the last cursor and pushes only
 * the differences to ShopWired. Fast, incremental path for near-real-time
 * stock accuracy. The full sync job handles any drift this misses.
 *
 * @see SyncDeltaStockToShopwiredUseCase
 * @see InventoryScheduleServiceProvider for schedule frequency.
 */
final class SyncDeltaStockToShopwiredJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;
    public int $maxExceptions = 2;

    /** @var array<int> */
    public array $backoff = [30];

    public int $timeout = 60;
    public bool $failOnTimeout = true;

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
            ServiceCircuitBreaker::linnworks(),
            ServiceCircuitBreaker::shopwired(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    public function handle(SyncDeltaStockToShopwiredUseCase $useCase): void
    {
        $useCase->execute();
    }
}
