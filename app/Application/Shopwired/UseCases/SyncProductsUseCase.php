<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\ProductClientInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\Shopwired\ValueObjects\SyncResult;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Exceptions\ResourceNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate product synchronization from ShopWired API to local database.
 *
 * Performs full catalog sync only (no incremental mode). ShopWired Products API
 * only supports title sorting, making date-based incremental sync impractical.
 *
 * Uses generator-based pagination for memory efficiency with ~1,500 products.
 *
 * Batching strategy:
 * - API returns ~100 products per page
 * - Buffer 10 pages (~1000 products) before DB write
 * - Reduces DB round-trips while keeping memory bounded
 *
 * Usage:
 * - Full sync: Daily job syncing all ~1,500 products (~2-5 min)
 */
final readonly class SyncProductsUseCase
{
    /**
     * Number of pages to buffer before writing to database.
     * 10 pages × ~100 products/page = ~1000 products per batch.
     */
    private const int PAGES_PER_BATCH = 10;

    /**
     * Log progress every N batches at info level.
     * 5 batches × ~1000 products/batch = ~5,000 products between progress logs.
     */
    private const int PROGRESS_LOG_INTERVAL = 5;

    public function __construct(
        private ProductClientInterface $productClient,
        private ProductRepositoryInterface $productRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize products from ShopWired API to local database.
     *
     * Iterates through product pages, buffering PAGES_PER_BATCH pages
     * before flushing to database. Uses continue-on-failure semantics:
     * individual save failures are logged and counted, but processing continues.
     *
     * @return SyncResult Results with fetched/saved/failed counts
     *
     * @throws AuthenticationExpiredException When ShopWired credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    public function execute(): SyncResult
    {
        $this->logger->info('Starting full product sync from ShopWired');

        $totalFetched = 0;
        $totalSaved = 0;
        $totalFailed = 0;
        /** @var list<int> $allFailedReferences */
        $allFailedReferences = [];

        /** @var list<Product> $buffer */
        $buffer = [];
        $pagesBuffered = 0;
        $batchesFlushed = 0;

        foreach ($this->productClient->iterateProductBatches() as $pageNumber => $products) {
            $totalFetched += \count($products);
            $buffer = [...$buffer, ...$products];
            $pagesBuffered++;

            $this->logger->debug('Fetched product page from API', [
                'page' => $pageNumber,
                'count' => \count($products),
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
                    $this->logger->info('Product sync progress', [
                        'fetched' => $totalFetched,
                        'saved' => $totalSaved,
                        'failed' => $totalFailed,
                    ]);
                }
            }
        }

        // Flush remaining products in buffer
        if ($buffer !== []) {
            $result = $this->flushBuffer($buffer, 'final');
            $totalSaved += $result->saved;
            $totalFailed += $result->failed;
            $allFailedReferences = [...$allFailedReferences, ...$result->failedReferences];
        }

        if ($totalFetched === 0) {
            $this->logger->info('Product sync completed: no products found in ShopWired');

            return SyncResult::empty();
        }

        $this->logger->info('Product sync completed', [
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
     * Flush buffered products to database.
     *
     * @param list<Product> $products Products to save
     * @param int|string $batchIdentifier For logging (page number or 'final')
     */
    private function flushBuffer(array $products, int|string $batchIdentifier): SyncResult
    {
        $this->logger->debug('Flushing product batch to database', [
            'batch' => $batchIdentifier,
            'count' => \count($products),
        ]);

        $saveResult = $this->productRepository->saveMany($products);

        if ($saveResult->hasFailures()) {
            $this->logger->error('Failed to save some products to database', [
                'batch' => $batchIdentifier,
                'failed_count' => $saveResult->failed,
                'failed_ids' => $saveResult->failedReferences,
            ]);
        }

        return new SyncResult(
            fetched: \count($products),
            saved: $saveResult->succeeded,
            failed: $saveResult->failed,
            failedReferences: $saveResult->failedReferences,
        );
    }
}
