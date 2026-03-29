<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\Contracts\Linnworks\OrderDashboardsClientInterface;
use App\Application\Linnworks\UseCases\BackfillLinnworksOrdersUseCase;
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
 * Backfill Linnworks orders for a specific date range.
 *
 * Safe for everyday use — requires explicit --from and --to flags.
 * Uses the Dashboards SQL API to retrieve order IDs (bypassing the
 * ~30-day fromDate limit), then fetches full orders via v2 REST.
 *
 * Examples:
 *   php artisan linnworks:backfill-orders --from=2025-01-01 --to=2025-02-01
 *   php artisan linnworks:backfill-orders --from=2026-03-01 --to=2026-03-29 --dry-run
 */
final class BackfillLinnworksOrdersCommand extends Command
{
    protected $signature = 'linnworks:backfill-orders
                            {--from= : Start date (Y-m-d) — REQUIRED}
                            {--to= : End date (Y-m-d) — REQUIRED}
                            {--dry-run : Show order count without syncing}';

    protected $description = 'Backfill Linnworks orders for a specific date range via SQL API';

    /**
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws ValueError When date string contains NULL-bytes
     */
    public function handle(
        BackfillLinnworksOrdersUseCase $useCase,
        OrderDashboardsClientInterface $dashboardsClient,
    ): int {
        $dates = $this->parseDateRange();

        if ($dates === null) {
            return self::FAILURE;
        }

        [$from, $to] = $dates;

        return $this->executeWithDates($useCase, $dashboardsClient, $from, $to);
    }

    /**
     * Parse and validate --from and --to options.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}|null
     *
     * @throws ValueError When date string contains NULL-bytes
     */
    private function parseDateRange(): ?array
    {
        $rawDates = $this->extractDateStrings();

        if ($rawDates === null) {
            return null;
        }

        return $this->createValidatedDates($rawDates[0], $rawDates[1]);
    }

    /**
     * Extract --from and --to as validated non-empty strings.
     *
     * @return array{string, string}|null
     */
    private function extractDateStrings(): ?array
    {
        $fromStr = $this->option('from');
        $toStr = $this->option('to');

        if (!\is_string($fromStr) || $fromStr === '' || !\is_string($toStr) || $toStr === '') {
            $this->error('Both --from and --to are required (format: Y-m-d)');

            return null;
        }

        return [$fromStr, $toStr];
    }

    /**
     * Parse date strings and validate ordering.
     *
     * @return array{DateTimeImmutable, DateTimeImmutable}|null
     *
     * @throws ValueError When date string contains NULL-bytes
     */
    private function createValidatedDates(string $fromStr, string $toStr): ?array
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
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function executeWithDates(
        BackfillLinnworksOrdersUseCase $useCase,
        OrderDashboardsClientInterface $dashboardsClient,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): int {
        $orderIds = $this->queryOrderIds($dashboardsClient, $from, $to);

        if ($orderIds === []) {
            $this->warn('No orders found in the specified date range.');

            return self::SUCCESS;
        }

        return $this->confirmAndRunBackfill($useCase, $orderIds);
    }

    /**
     * Handle dry-run check, then execute backfill.
     *
     * @param list<Guid> $orderIds
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function confirmAndRunBackfill(BackfillLinnworksOrdersUseCase $useCase, array $orderIds): int
    {
        $this->info('Found ' . \count($orderIds) . ' orders in date range.');

        if ($this->option('dry-run')) {
            $this->warn('Dry run — no orders synced. Remove --dry-run to execute.');

            return self::SUCCESS;
        }

        return $this->runBackfill($useCase, $orderIds);
    }

    /**
     * Query Linnworks for order IDs in the given date range.
     *
     * @return list<Guid>
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function queryOrderIds(
        OrderDashboardsClientInterface $dashboardsClient,
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array {
        $this->info("Querying Linnworks SQL API for order IDs ({$from->format('Y-m-d')} to {$to->format('Y-m-d')})...");

        return $dashboardsClient->getProcessedOrderIdsByOrderDate($from, $to);
    }

    /**
     * Execute the backfill and output results.
     *
     * @param list<Guid> $orderIds
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function runBackfill(BackfillLinnworksOrdersUseCase $useCase, array $orderIds): int
    {
        $this->info('Backfilling ' . \count($orderIds) . ' orders...');
        $result = $useCase->execute($orderIds);

        $this->info("Backfill complete: {$result->saved} saved, {$result->failed} failed (of {$result->fetched} fetched).");

        if ($result->hasFailures()) {
            $this->warn('Failed order IDs: ' . \implode(', ', $result->failedReferences));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
