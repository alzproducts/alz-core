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
 * Hourly backup sync for all open (pending/unpaid) Linnworks orders.
 *
 * Queries all open order IDs from the Dashboards SQL API then fetches
 * full orders via the v2 REST endpoint. Ensures open orders are always
 * up-to-date even if missed by the cursor-based sync.
 */
final class SyncAllOpenLinnworksOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 4;

    public int $maxExceptions = 2;

    public bool $failOnTimeout = true;

    /** @var array<int> */
    public array $backoff = [30];

    /**
     * 90s — only ~2 API calls for typical open order volume.
     */
    public int $timeout = 90;

    public int $uniqueFor = 120;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return 'sync-all-open-linnworks-orders';
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
        $orderIds = $dashboardsClient->getOpenOrderIds();

        if ($orderIds !== []) {
            $useCase->execute($orderIds);
        }
    }
}
