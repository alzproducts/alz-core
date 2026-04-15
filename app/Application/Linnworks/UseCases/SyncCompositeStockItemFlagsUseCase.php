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
 * Sync is_composite flags from Linnworks to local database.
 *
 * Fetches composite parent stock item IDs via SQL Dashboards query,
 * then performs a two-pass bulk update to keep the `is_composite` column
 * accurate for all active stock items.
 */
final readonly class SyncCompositeStockItemFlagsUseCase
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
        $this->logger->info('Syncing composite stock item flags from Linnworks');

        $compositeIds = $this->stockDashboardsClient->getCompositeStockItemIds();

        $this->stockItemRepository->syncCompositeFlags($compositeIds);

        $this->logger->info('Composite stock item flags synced', [
            'composite_count' => \count($compositeIds),
        ]);
    }
}
