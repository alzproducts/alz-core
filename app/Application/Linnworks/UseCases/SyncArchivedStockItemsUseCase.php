<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Sync archived stock items from Linnworks to local DB.
 *
 * Fetches full field data for every archived item via the SQL Dashboards
 * endpoint (the Inventory REST API silently filters these rows out) and
 * upserts them into `linnworks.stock_items`. Stock levels are zero-filled
 * — archived items have no live stock by definition.
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
        $this->logger->info('Starting archived stock items sync');

        $items = $this->stockDashboardsClient->getArchivedStockItemsFull();
        if ($items === []) {
            $this->logger->info('No archived stock items found');

            return;
        }

        $result = $this->stockItemRepository->upsertArchivedStockItems($items);
        \gc_collect_cycles();
        \gc_mem_caches();

        $this->logger->info('Completed archived stock items sync', [
            'total_fetched' => \count($items),
            'succeeded' => $result->succeeded,
            'failed' => $result->failed,
        ]);
    }
}
