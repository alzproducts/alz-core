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
use Illuminate\Console\Command;

/**
 * Backfill ALL historical Linnworks orders.
 *
 * CAUTION: This syncs all historical orders (~110,000+). This is a
 * long-running operation that may take several hours and makes
 * significant API calls. Use linnworks:backfill-orders for targeted
 * date-range syncs instead.
 *
 * Examples:
 *   php artisan linnworks:backfill-all-orders --dry-run
 *   php artisan linnworks:backfill-all-orders --force
 */
final class BackfillAllLinnworksOrdersCommand extends Command
{
    protected $signature = 'linnworks:backfill-all-orders
                            {--dry-run : Show total order count without syncing}
                            {--force : Skip confirmation prompt (for scripts/automation)}';

    protected $description = 'CAUTION: Backfill ALL historical Linnworks orders (~110,000+). Long-running operation.';

    /**
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function handle(
        BackfillLinnworksOrdersUseCase $useCase,
        OrderDashboardsClientInterface $dashboardsClient,
    ): int {
        $this->printWarningBanner();
        $orderIds = $this->queryOrderIds($dashboardsClient);

        if ($orderIds === []) {
            $this->warn('No processed orders found.');

            return self::SUCCESS;
        }

        $count = \count($orderIds);
        $this->info("Found {$count} processed orders.");

        return $this->confirmAndExecute($useCase, $orderIds, $count);
    }

    /**
     * Handle dry-run, confirmation, and execution.
     *
     * @param list<Guid> $orderIds
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function confirmAndExecute(BackfillLinnworksOrdersUseCase $useCase, array $orderIds, int $count): int
    {
        if ($this->option('dry-run')) {
            $this->warn('Dry run — no orders synced. Remove --dry-run to execute.');

            return self::SUCCESS;
        }

        if (! $this->shouldProceed($count)) {
            return self::SUCCESS;
        }

        return $this->executeBackfill($useCase, $orderIds, $count);
    }

    private function printWarningBanner(): void
    {
        $this->warn('=== FULL HISTORICAL BACKFILL ===');
        $this->warn('This will sync ALL processed orders from Linnworks.');
        $this->newLine();
    }

    /**
     * @return list<Guid>
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function queryOrderIds(OrderDashboardsClientInterface $dashboardsClient): array
    {
        $this->info('Querying Linnworks SQL API for all processed order IDs...');

        return $dashboardsClient->getProcessedOrderIdsByOrderDate();
    }

    private function shouldProceed(int $count): bool
    {
        if ($this->option('force')) {
            return true;
        }

        $this->warn("This will sync {$count} orders. This may take several hours.");

        if (! $this->confirm('Type "yes" to continue')) {
            $this->info('Aborted.');

            return false;
        }

        return true;
    }

    /**
     * @param list<Guid> $orderIds
     *
     * @throws InvalidApiResponseException
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    private function executeBackfill(BackfillLinnworksOrdersUseCase $useCase, array $orderIds, int $count): int
    {
        $this->info("Backfilling {$count} orders...");

        $result = $useCase->execute($orderIds);

        $this->newLine();
        $this->info("Backfill complete: {$result->saved} saved, {$result->failed} failed (of {$result->fetched} fetched).");

        if ($result->hasFailures()) {
            $this->warn('Failed order IDs: ' . \implode(', ', $result->failedReferences));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
