<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Contracts\Linnworks\OrderDashboardsClientInterface;
use App\Application\Linnworks\UseCases\BackfillLinnworksOrdersUseCase;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Backfill ALL historical Linnworks orders via SQL API.
 *
 * Queries all processed order IDs from the Dashboards SQL API
 * (no date filter), then fetches full orders via v2 REST.
 * Typical runtime: ~1.5 hours for ~115k orders.
 */
final class SyncHistoricalLinnworksOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 1;

    public int $maxExceptions = 1;

    public bool $failOnTimeout = true;

    /** @var array<int> Required by JobRequiredMethodsRule; unused with $tries=1 */
    public array $backoff = [120, 600];

    /**
     * 12 hours — production remote Supabase PostgreSQL adds ~29ms per query
     * vs <1ms locally. Each of ~115k orders requires 4-5 queries in its own
     * transaction (~175ms/order), giving ~5.6h DB time + ~1h API fetch = ~6.6h
     * estimated. Local benchmark (1h24m) is not representative of prod latency.
     * Extended from 8h to 12h to fit within the background queue's retry_after (43800s).
     */
    public int $timeout = 43200;

    public int $uniqueFor = 50400;

    public function __construct()
    {
        $this->onQueue(QueueName::Background->value);
    }

    public function uniqueId(): string
    {
        return 'sync-historical-linnworks-orders';
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            ServiceCircuitBreaker::linnworks(),
            new HandleApiExceptions(),
        ];
    }

    public function handle(
        BackfillLinnworksOrdersUseCase $useCase,
        OrderDashboardsClientInterface $dashboardsClient,
    ): void {
        $orderIds = $dashboardsClient->getProcessedOrderIdsByOrderDate();

        if ($orderIds !== []) {
            $useCase->execute($orderIds);
        }
    }
}
