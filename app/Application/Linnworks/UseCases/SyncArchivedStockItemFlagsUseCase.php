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
 * Sync is_archived and is_logically_deleted flags from Linnworks to local database.
 *
 * Fetches only flagged items (archived or logically deleted) via the
 * ExecuteCustomScriptQuery SQL endpoint, then performs targeted bulk updates
 * to avoid touching rows that haven't changed.
 */
final readonly class SyncArchivedStockItemFlagsUseCase
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
        $this->logger->info('Syncing archived stock item flags from Linnworks');

        $flags = $this->stockDashboardsClient->getArchivedStockItemIds();

        $this->stockItemRepository->syncArchivedFlags(
            archivedIds: $flags->archivedIds,
            deletedIds: $flags->deletedIds,
        );

        $this->logger->info('Archived stock item flags synced', [
            'archived' => \count($flags->archivedIds),
            'deleted' => \count($flags->deletedIds),
        ]);
    }
}
