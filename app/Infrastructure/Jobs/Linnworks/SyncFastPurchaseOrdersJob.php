<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Contracts\Linnworks\PurchaseDashboardsClientInterface;
use App\Application\Linnworks\UseCases\SyncPurchaseOrderCoreUseCase;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Fast purchase order sync — queries OPEN/PENDING/PARTIAL + today's DELIVERED POs.
 *
 * Fetches Core data only (1 API call/PO). Runs every 5 minutes to keep
 * active purchase orders up-to-date without the overhead of full metadata.
 * Skipped silently when no IDs match the filter.
 */
final class SyncFastPurchaseOrdersJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 4;

    public int $maxExceptions = 2;
    /** @var array<int> */
    public array $backoff = [30, 120];

    /**
     * 5 minutes — fast sync processes at most ~6 months of open POs.
     */
    public int $timeout = 300;

    /**
     * 10 minutes — prevents overlapping with the next scheduled run.
     */
    public int $uniqueFor = 600;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return 'sync-fast-purchase-orders';
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
            new HandleDatabaseExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(1)->toDateTimeImmutable();
    }

    /**
     * IMPORTANT: createdSince calculated here in handle(), not constructor (Octane safety).
     * Uses startOfMonth()->subMonths(6) rather than subMonths(6) directly to avoid
     * month-boundary gaps — e.g. on Jan 15 we want Jul 1, not Jul 15.
     *
     * @throws DatabaseOperationFailedException On DB query failure
     * @throws DuplicateRecordException On duplicate record
     */
    public function handle(
        SyncPurchaseOrderCoreUseCase $useCase,
        PurchaseDashboardsClientInterface $dashboardsClient,
    ): void {
        $createdSince = \now()->startOfMonth()->subMonths(6)->toDateTimeImmutable();

        $ids = $dashboardsClient->getFastSyncPurchaseOrderIds(
            createdSince: $createdSince,
            includeDeliveredToday: true,
        );

        if ($ids !== []) {
            $useCase->execute($ids);
        }
    }
}
