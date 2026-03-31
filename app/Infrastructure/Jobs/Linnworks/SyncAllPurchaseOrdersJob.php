<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Linnworks;

use App\Application\Contracts\Linnworks\PurchaseDashboardsClientInterface;
use App\Application\Linnworks\UseCases\SyncPurchaseOrderFullUseCase;
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
 * Full historical purchase order sync — fetches ALL POs with no filters.
 *
 * Fetches Full data (3 API calls/PO) for every PO in the system. Very
 * long-running — intended for manual backfill via BackfillPurchaseOrdersCommand
 * with --all, or for ad-hoc dispatch in extraordinary circumstances.
 */
final class SyncAllPurchaseOrdersJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 2;

    public bool $failOnTimeout = true;

    /** @var array<int> */
    public array $backoff = [120, 600];

    /**
     * 6 hours — every PO × 3 API calls; typical prod volume can be 10,000+ POs.
     * Full sync takes ~2h locally; prod DB latency pushes this significantly higher.
     */
    public int $timeout = 21600;

    /**
     * 8 hours — prevents a second full-sync starting while first is still running.
     */
    public int $uniqueFor = 28800;

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    public function uniqueId(): string
    {
        return 'sync-all-purchase-orders';
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

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    public function handle(
        SyncPurchaseOrderFullUseCase $useCase,
        PurchaseDashboardsClientInterface $dashboardsClient,
    ): void {
        $ids = $dashboardsClient->getAllPurchaseOrderIds();

        if ($ids !== []) {
            $useCase->execute($ids);
        }
    }
}
