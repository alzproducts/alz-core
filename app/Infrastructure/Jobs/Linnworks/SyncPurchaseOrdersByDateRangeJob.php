<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Contracts\Linnworks\PurchaseDashboardsClientInterface;
use App\Application\Linnworks\UseCases\SyncPurchaseOrderFullUseCase;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Normal purchase order sync for a specific date range.
 *
 * Queries all POs (any status) where DateOfDelivery or DateOfPurchase falls
 * within the range. Fetches Full data (3 API calls/PO). Dispatched daily
 * for the last 7 days by LinnworksScheduleServiceProvider, or manually
 * via BackfillPurchaseOrdersCommand with --from/--to.
 */
final class SyncPurchaseOrdersByDateRangeJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 3;

    public int $maxExceptions = 2;
    /** @var array<int> */
    public array $backoff = [60, 300];

    /**
     * 1 hour — full sync for a 7-day window with 3 API calls/PO.
     */
    public int $timeout = 3600;

    /**
     * 2 hours — prevents a duplicate range job enqueuing while the first is still running.
     */
    public int $uniqueFor = 7200;

    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
    ) {
        $this->onQueue(QueueName::Low->value);
    }

    public function uniqueId(): string
    {
        return 'sync-purchase-orders-date-range-'
            . $this->from->format('Y-m-d') . '-'
            . $this->to->format('Y-m-d');
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
        SyncPurchaseOrderFullUseCase $useCase,
        PurchaseDashboardsClientInterface $dashboardsClient,
    ): void {
        $ids = $dashboardsClient->getPurchaseOrderIdsByDateRange($this->from, $this->to);

        if ($ids !== []) {
            $useCase->execute($ids);
        }
    }
}
