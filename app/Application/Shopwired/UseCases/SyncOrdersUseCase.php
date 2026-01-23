<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
use App\Application\ValueObjects\SyncResult;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate order synchronization from ShopWired API to local database.
 *
 * Supports both full sync (all orders) and quick sync (recent orders only).
 * Uses generator-based pagination for memory efficiency with large order volumes.
 *
 * Batching strategy:
 * - API returns ~100 orders per page
 * - Buffer 10 pages (~1000 orders) before DB write
 * - Reduces DB round-trips while keeping memory bounded
 *
 * Usage:
 * - Full sync (null): Daily job syncing all orders
 * - Quick sync (5): Hourly job catching recent orders (~500 orders)
 * - Micro sync (1): Every 5 min job (~100 orders)
 *
 * @see SyncOrdersRangeUseCase For date-range based sync (operational flexibility)
 */
final readonly class SyncOrdersUseCase
{
    /**
     * Number of pages to buffer before writing to database.
     * 10 pages × ~100 orders/page = ~1000 orders per batch.
     */
    private const int PAGES_PER_BATCH = 10;

    /**
     * Log progress every N batches at info level.
     * 10 batches × ~1000 orders/batch = ~10,000 orders between progress logs.
     */
    private const int PROGRESS_LOG_INTERVAL = 10;

    public function __construct(
        private OrderClientInterface $orderClient,
        private OrderRepositoryInterface $orderRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize orders from ShopWired API to local database.
     *
     * Iterates through order pages, buffering PAGES_PER_BATCH pages
     * before flushing to database. Uses continue-on-failure semantics:
     * individual save failures are logged and counted, but processing continues.
     *
     * @param int|null $maxPages Max pages to fetch (null = all, 1 page ≈ 100 orders)
     *
     * @return SyncResult Results with fetched/saved/failed counts
     *
     * @throws AuthenticationExpiredException When ShopWired credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    public function execute(?int $maxPages = null): SyncResult
    {
        $syncType = $maxPages === null ? 'full' : "limited ({$maxPages} pages)";
        $this->logger->info("Starting {$syncType} order sync from ShopWired");

        $totalFetched = 0;
        $totalSaved = 0;
        $totalFailed = 0;
        /** @var list<int> $allFailedReferences */
        $allFailedReferences = [];

        /** @var list<Order> $buffer */
        $buffer = [];
        $pagesBuffered = 0;
        $batchesFlushed = 0;

        foreach ($this->orderClient->iterateOrderBatches($maxPages) as $pageNumber => $orders) {
            $totalFetched += \count($orders);
            $buffer = [...$buffer, ...$orders];
            $pagesBuffered++;

            $this->logger->debug('Fetched order page from API', [
                'page' => $pageNumber,
                'count' => \count($orders),
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
                    $this->logger->info('Order sync progress', [
                        'fetched' => $totalFetched,
                        'saved' => $totalSaved,
                        'failed' => $totalFailed,
                    ]);
                }
            }
        }

        // Flush remaining orders in buffer
        if ($buffer !== []) {
            $result = $this->flushBuffer($buffer, 'final');
            $totalSaved += $result->saved;
            $totalFailed += $result->failed;
            $allFailedReferences = [...$allFailedReferences, ...$result->failedReferences];
        }

        if ($totalFetched === 0) {
            $this->logger->info('Order sync completed: no orders found in ShopWired');

            return SyncResult::empty();
        }

        $this->logger->info('Order sync completed', [
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
     * Flush buffered orders to database.
     *
     * @param list<Order> $orders Orders to save
     * @param int|string $batchIdentifier For logging (page number or 'final')
     */
    private function flushBuffer(array $orders, int|string $batchIdentifier): SyncResult
    {
        $this->logger->debug('Flushing order batch to database', [
            'batch' => $batchIdentifier,
            'count' => \count($orders),
        ]);

        $saveResult = $this->orderRepository->saveMany($orders);

        if ($saveResult->hasFailures()) {
            $this->logger->error('Failed to save some orders to database', [
                'batch' => $batchIdentifier,
                'failed_count' => $saveResult->failed,
                'failed_ids' => $saveResult->failedReferences,
            ]);
        }

        return new SyncResult(
            fetched: \count($orders),
            saved: $saveResult->succeeded,
            failed: $saveResult->failed,
            failedReferences: $saveResult->failedReferences,
        );
    }
}
