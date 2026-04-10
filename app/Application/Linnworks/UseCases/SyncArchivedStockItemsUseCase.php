<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\Results\SaveManyResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Sync archived and logically-deleted stock items from Linnworks to local DB.
 *
 * Fetches full field data for every archived / logically-deleted item via
 * the SQL Dashboards endpoint (the Inventory REST API silently filters
 * these rows out) and upserts them into `linnworks.stock_items`. Stock
 * levels are zero-filled — archived items have no live stock by definition.
 *
 * Historical `stock_item_extended_properties` and `stock_item_suppliers`
 * rows are preserved for items transitioning to archived state — the
 * repository bypasses the regular save() path that would DELETE them.
 *
 * Designed to run weekly. Complements {@see SyncArchivedStockItemFlagsUseCase}
 * which runs hourly and only flips flags on rows that already exist.
 */
final readonly class SyncArchivedStockItemsUseCase
{
    public function __construct(
        private StockDashboardsClientInterface $stockDashboardsClient,
        private StockItemRepositoryInterface $stockItemRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function execute(): void
    {
        $startedAt = \microtime(true);
        $this->logger->info('Starting archived stock items sync');

        $records = $this->stockDashboardsClient->getArchivedStockItemsFull();
        if ($records === []) {
            $this->logger->info('No archived stock items found');

            return;
        }

        $result = $this->stockItemRepository->upsertArchivedStockItems($records);
        \gc_collect_cycles();
        \gc_mem_caches();

        $this->logCompletion(\count($records), $result, $startedAt);
    }

    private function logCompletion(int $totalFetched, SaveManyResult $result, float $startedAt): void
    {
        $this->logger->info('Completed archived stock items sync', [
            'total_fetched' => $totalFetched,
            'succeeded' => $result->succeeded,
            'failed' => $result->failed,
            'duration_seconds' => \round(\microtime(true) - $startedAt, 2),
            'memory_mb' => \round(\memory_get_usage(false) / 1024 / 1024, 1),
            'peak_memory_mb' => \round(\memory_get_peak_usage(false) / 1024 / 1024, 1),
        ]);
    }
}
