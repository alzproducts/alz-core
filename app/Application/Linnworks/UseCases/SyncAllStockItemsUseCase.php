<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Application\ValueObjects\SyncResult;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Domain\Inventory\ValueObjects\StockItem;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate stock item synchronization from Linnworks API to local database.
 *
 * Full sync strategy: fetches all ~10k stock items with extended properties
 * and upserts them to the database. Designed for daily 5am execution.
 *
 * Uses generator-based pagination for memory efficiency.
 *
 * Batching strategy:
 * - API returns ~200 items per page
 * - Buffer 5 pages (~1000 items) before DB write
 * - Reduces DB round-trips while keeping memory bounded
 */
final readonly class SyncAllStockItemsUseCase
{
    /**
     * Number of pages to buffer before writing to database.
     * 5 pages × ~200 items/page = ~1000 items per batch.
     */
    private const int PAGES_PER_BATCH = 5;

    /**
     * Log progress every N batches at info level.
     * 5 batches × ~1000 items/batch = ~5,000 items between progress logs.
     */
    private const int PROGRESS_LOG_INTERVAL = 5;

    public function __construct(
        private InventoryClientInterface $inventoryClient,
        private StockItemRepositoryInterface $stockItemRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize all stock items from Linnworks API to local database.
     *
     * Iterates through stock item pages, buffering PAGES_PER_BATCH pages
     * before flushing to database. Uses continue-on-failure semantics:
     * individual save failures are logged and counted, but processing continues.
     *
     * @return SyncResult Results with fetched/saved/failed counts
     *
     * @throws AuthenticationExpiredException When Linnworks credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When Linnworks API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    public function execute(): SyncResult
    {
        $this->logger->info('Starting full stock item sync from Linnworks');

        $totalFetched = 0;
        $totalSaved = 0;
        $totalFailed = 0;
        /** @var list<string> $allFailedReferences */
        $allFailedReferences = [];

        /** @var list<StockItem> $buffer */
        $buffer = [];
        $pagesBuffered = 0;
        $batchesFlushed = 0;

        foreach ($this->inventoryClient->iterateStockItemBatches() as $pageNumber => $stockItems) {
            $totalFetched += \count($stockItems);
            $buffer = [...$buffer, ...$stockItems];
            $pagesBuffered++;

            $this->logger->debug('Fetched stock item page from API', [
                'page' => $pageNumber,
                'count' => \count($stockItems),
                'buffer_size' => \count($buffer),
            ]);

            // Flush buffer when we've accumulated enough pages
            if ($pagesBuffered >= self::PAGES_PER_BATCH) {
                $result = $this->flushBuffer($buffer, $pageNumber);
                $totalSaved += $result->saved;
                $totalFailed += $result->failed;
                $allFailedReferences = [...$allFailedReferences, ...$result->failedReferences];

                $buffer = [];
                $pagesBuffered = 0;
                $batchesFlushed++;

                // Log progress at info level periodically for operator visibility
                if ($batchesFlushed % self::PROGRESS_LOG_INTERVAL === 0) {
                    $this->logger->info('Stock item sync progress', [
                        'fetched' => $totalFetched,
                        'saved' => $totalSaved,
                        'failed' => $totalFailed,
                    ]);
                }
            }
        }

        // Flush remaining items in buffer
        if ($buffer !== []) {
            $result = $this->flushBuffer($buffer, 'final');
            $totalSaved += $result->saved;
            $totalFailed += $result->failed;
            $allFailedReferences = [...$allFailedReferences, ...$result->failedReferences];
        }

        if ($totalFetched === 0) {
            $this->logger->info('Stock item sync completed: no items found in Linnworks');

            return SyncResult::empty();
        }

        $this->logger->info('Stock item sync completed', [
            'fetched' => $totalFetched,
            'saved' => $totalSaved,
            'failed' => $totalFailed,
        ]);

        return new SyncResult(
            fetched: $totalFetched,
            saved: $totalSaved,
            failed: $totalFailed,
            failedReferences: $allFailedReferences,
        );
    }

    /**
     * Flush buffered stock items to database.
     *
     * @param list<StockItem> $stockItems Items to save
     * @param int|string $batchIdentifier For logging (page number or 'final')
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function flushBuffer(array $stockItems, int|string $batchIdentifier): SyncResult
    {
        $this->logger->debug('Flushing stock item batch to database', [
            'batch' => $batchIdentifier,
            'count' => \count($stockItems),
        ]);

        $saveResult = $this->stockItemRepository->saveMany($stockItems);

        if ($saveResult->hasFailures()) {
            $this->logger->error('Failed to save some stock items to database', [
                'batch' => $batchIdentifier,
                'failed_count' => $saveResult->failed,
                'failed_ids' => $saveResult->failedReferences,
            ]);
        }

        return new SyncResult(
            fetched: \count($stockItems),
            saved: $saveResult->succeeded,
            failed: $saveResult->failed,
            failedReferences: $saveResult->failedReferences,
        );
    }
}
