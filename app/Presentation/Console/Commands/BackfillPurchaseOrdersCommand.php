<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Contracts\Linnworks\PurchaseDashboardsClientInterface;
use App\Application\Contracts\Linnworks\PurchaseOrderBackfillDispatcherInterface;
use App\Application\Linnworks\UseCases\SyncPurchaseOrderFullUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\ValueObjects\Guid;
use DateTimeImmutable;
use Illuminate\Console\Command;
use ValueError;

/**
 * Backfill Linnworks purchase orders via full or date-range sync.
 *
 * Safe for everyday use. Requires explicit --all or --from/--to flags.
 * Uses the Dashboards SQL API to retrieve PO IDs, then fetches full PO
 * data (3 API calls/PO) via the REST endpoint.
 *
 * Examples:
 *   php artisan linnworks:backfill-purchase-orders --from=2025-01-01 --to=2025-02-01
 *   php artisan linnworks:backfill-purchase-orders --all --queue
 *   php artisan linnworks:backfill-purchase-orders --from=2026-01-01 --to=2026-04-01 --dry-run
 */
final class BackfillPurchaseOrdersCommand extends Command
{
    protected $signature = 'linnworks:backfill-purchase-orders
                            {--from= : Start date (Y-m-d) — required unless --all}
                            {--to= : End date (Y-m-d) — required unless --all}
                            {--all : Full backfill — sync ALL purchase orders (long-running)}
                            {--dry-run : Show PO count without syncing}
                            {--queue : Dispatch to queue instead of running inline}';

    protected $description = 'Backfill Linnworks purchase orders (full or date range) via SQL + REST API';

    /**
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws ValueError
     */
    public function handle(
        SyncPurchaseOrderFullUseCase $useCase,
        PurchaseDashboardsClientInterface $dashboardsClient,
        PurchaseOrderBackfillDispatcherInterface $dispatcher,
    ): int {
        if ($this->option('all')) {
            return $this->handleAllBackfill($useCase, $dashboardsClient, $dispatcher);
        }

        return $this->handleDateRangeBackfill($useCase, $dashboardsClient, $dispatcher);
    }

    /**
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function handleAllBackfill(
        SyncPurchaseOrderFullUseCase $useCase,
        PurchaseDashboardsClientInterface $dashboardsClient,
        PurchaseOrderBackfillDispatcherInterface $dispatcher,
    ): int {
        if ($this->option('queue')) {
            return $this->dispatchAllToQueue($dispatcher);
        }

        $ids = $this->fetchAllIds($dashboardsClient);

        if ($ids === null) {
            return self::SUCCESS;
        }

        $count = \count($ids);
        $this->warn("This will sync {$count} purchase orders (3 API calls each). May take several hours.");

        return $this->confirm('Type "yes" to continue') ? $this->runBackfill($useCase, $ids) : self::SUCCESS;
    }

    /**
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws ValueError
     */
    private function handleDateRangeBackfill(
        SyncPurchaseOrderFullUseCase $useCase,
        PurchaseDashboardsClientInterface $dashboardsClient,
        PurchaseOrderBackfillDispatcherInterface $dispatcher,
    ): int {
        $strings = $this->parseDateRange();
        if ($strings === null) {
            return self::FAILURE;
        }
        $dates = $this->parseDateStrings($strings[0], $strings[1]);
        if ($dates === null) {
            return self::FAILURE;
        }
        [$from, $to] = $dates;
        if ($this->option('queue')) {
            return $this->dispatchDateRangeToQueue($dispatcher, $from, $to);
        }
        $ids = $this->fetchDateRangeIds($dashboardsClient, $from, $to);

        return $ids !== null ? $this->runBackfill($useCase, $ids) : self::SUCCESS;
    }

    private function dispatchAllToQueue(PurchaseOrderBackfillDispatcherInterface $dispatcher): int
    {
        $dispatcher->dispatchAllBackfill();
        $this->info('Dispatched full purchase order backfill to queue.');
        $this->warn('Monitor progress in Horizon. May take several hours.');

        return self::SUCCESS;
    }

    private function dispatchDateRangeToQueue(
        PurchaseOrderBackfillDispatcherInterface $dispatcher,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): int {
        $dispatcher->dispatchDateRangeBackfill($from, $to);
        $this->info("Dispatched purchase order backfill to queue ({$from->format('Y-m-d')} to {$to->format('Y-m-d')}).");

        return self::SUCCESS;
    }

    /**
     * @return list<Guid>|null
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function fetchAllIds(PurchaseDashboardsClientInterface $dashboardsClient): ?array
    {
        $this->warn('=== FULL PURCHASE ORDER BACKFILL ===');
        $this->warn('This will sync ALL purchase orders from Linnworks.');
        $this->newLine();
        $this->info('Querying Linnworks SQL API for all purchase order IDs...');
        $ids = $dashboardsClient->getAllPurchaseOrderIds();

        if ($ids === []) {
            $this->warn('No purchase orders found.');

            return null;
        }

        $this->info('Found ' . \count($ids) . ' purchase orders.');

        return $this->checkDryRun($ids);
    }

    /**
     * @return list<Guid>|null
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function fetchDateRangeIds(
        PurchaseDashboardsClientInterface $dashboardsClient,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): ?array {
        $this->info("Querying Linnworks SQL API for purchase order IDs ({$from->format('Y-m-d')} to {$to->format('Y-m-d')})...");
        $ids = $dashboardsClient->getPurchaseOrderIdsByDateRange($from, $to);

        if ($ids === []) {
            $this->warn('No purchase orders found in the specified date range.');

            return null;
        }

        $this->info('Found ' . \count($ids) . ' purchase orders in date range.');

        return $this->checkDryRun($ids);
    }

    /**
     * @param list<Guid> $ids
     *
     * @return list<Guid>|null
     */
    private function checkDryRun(array $ids): ?array
    {
        if ($this->option('dry-run')) {
            $this->warn('Dry run — no POs synced. Remove --dry-run to execute.');

            return null;
        }

        return $ids;
    }

    /** @return array{string, string}|null */
    private function parseDateRange(): ?array
    {
        $fromStr = $this->option('from');
        $toStr = $this->option('to');

        if (!\is_string($fromStr) || $fromStr === '' || !\is_string($toStr) || $toStr === '') {
            $this->error('Both --from and --to are required when --all is not specified (format: Y-m-d)');

            return null;
        }

        return [$fromStr, $toStr];
    }

    /**
     * @return array{DateTimeImmutable, DateTimeImmutable}|null
     *
     * @throws ValueError
     */
    private function parseDateStrings(string $fromStr, string $toStr): ?array
    {
        $from = DateTimeImmutable::createFromFormat('Y-m-d', $fromStr);
        $to = DateTimeImmutable::createFromFormat('Y-m-d', $toStr);

        if ($from === false || $to === false) {
            $this->error('Invalid date format. Use Y-m-d (e.g., 2025-01-15)');

            return null;
        }

        [$from, $to] = [$from->setTime(0, 0), $to->setTime(0, 0)];

        if ($from >= $to) {
            $this->error('--from must be before --to');

            return null;
        }

        return [$from, $to];
    }

    /**
     * @param list<Guid> $ids
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function runBackfill(SyncPurchaseOrderFullUseCase $useCase, array $ids): int
    {
        $this->info('Syncing ' . \count($ids) . ' purchase orders...');
        $result = $useCase->execute($ids);
        $this->newLine();
        $this->info("Sync complete: {$result->saved} saved, {$result->failed} failed (of {$result->fetched} fetched).");

        if ($result->hasFailures()) {
            $this->warn('Failed PO IDs: ' . \implode(', ', $result->failedReferences));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
