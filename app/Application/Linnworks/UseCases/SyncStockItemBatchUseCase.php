<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\PartialPersistenceFailureException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Synchronize multiple stock items from Linnworks API to local database in a single batch.
 *
 * Fetches full items (with extended properties and suppliers) via one batch API call
 * and persists them with continue-on-failure semantics.
 */
final readonly class SyncStockItemBatchUseCase
{
    public function __construct(
        private InventoryClientInterface $inventoryClient,
        private StockItemRepositoryInterface $stockItemRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Fetch and persist multiple stock items by their Linnworks GUIDs.
     *
     * @param list<Guid> $stockItemIds
     *
     * @throws AuthenticationExpiredException When Linnworks credentials invalid/expired
     * @throws ExternalServiceUnavailableException When Linnworks API or database unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ResourceNotFoundException When stock item doesn't exist in Linnworks
     * @throws PartialPersistenceFailureException When some stock items fail to persist
     */
    public function execute(array $stockItemIds): void
    {
        $this->logger->info('Syncing stock item batch', ['count' => \count($stockItemIds)]);

        $stockItems = $this->inventoryClient->getStockItemsFullByIds($stockItemIds);
        $saveResult = $this->stockItemRepository->saveMany($stockItems);

        if ($saveResult->hasFailures()) {
            throw new PartialPersistenceFailureException(
                succeeded: $saveResult->succeeded,
                failed: $saveResult->failed,
                failedReferences: $saveResult->failedReferences,
            );
        }

        $this->logger->debug('Stock items batch synced', [
            'count' => $saveResult->succeeded,
        ]);
    }
}
