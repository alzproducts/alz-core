<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Contracts\Linnworks\OrderDashboardsClientInterface;
use App\Application\Linnworks\UseCases\BackfillLinnworksOrdersUseCase;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Backfill Linnworks orders for a specific date range via SQL API.
 *
 * Queries order IDs by received date (not processed date) from the
 * Dashboards SQL API, then fetches full orders via v2 REST.
 * Typical runtime: ~1 minute per 700 orders.
 */
final class SyncLinnworksOrdersByDateRangeJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 3;

    public int $maxExceptions = 2;
    /** @var array<int> */
    public array $backoff = [60, 300];

    /**
     * 1 hour — covers up to ~42k orders at observed throughput.
     */
    public int $timeout = 3600;

    public int $uniqueFor = 3900;

    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
    ) {
        $this->onQueue(QueueName::Low->value);
    }

    public function uniqueId(): string
    {
        return 'sync-linnworks-orders-by-date-range-' . $this->from->format('Y-m-d') . '-' . $this->to->format('Y-m-d');
    }

    /**
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

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(6)->toDateTimeImmutable();
    }

    public function handle(
        BackfillLinnworksOrdersUseCase $useCase,
        OrderDashboardsClientInterface $dashboardsClient,
    ): void {
        $orderIds = $dashboardsClient->getProcessedOrderIdsByOrderDate($this->from, $this->to);

        if ($orderIds !== []) {
            $useCase->execute($orderIds);
        }
    }
}
