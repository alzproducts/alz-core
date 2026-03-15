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
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Synchronize a single stock item from Linnworks API to local database.
 *
 * Fetches the full item (with extended properties) and upserts to the database.
 * Used by SyncStockItemJob for cursor-based incremental sync.
 */
final readonly class SyncStockItemUseCase
{
    public function __construct(
        private InventoryClientInterface $inventoryClient,
        private StockItemRepositoryInterface $stockItemRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Fetch and persist a single stock item by its Linnworks GUID.
     *
     * @throws AuthenticationExpiredException When Linnworks credentials invalid/expired
     * @throws ExternalServiceUnavailableException When Linnworks API or database unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ResourceNotFoundException When stock item doesn't exist in Linnworks
     * @throws DatabaseOperationFailedException When local DB operations fail
     * @throws DuplicateRecordException When unique constraint violated
     */
    public function execute(Guid $stockItemId): void
    {
        $stockItem = $this->inventoryClient->getStockItemFull($stockItemId);
        $this->stockItemRepository->save($stockItem);

        $this->logger->debug('Stock item synced', [
            'stock_item_id' => $stockItemId->value,
        ]);
    }
}
