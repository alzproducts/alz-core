<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Results\SyncResult;
use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate customer synchronization from ShopWired API to local database.
 *
 * Supports both full sync (all customers) and quick sync (recent customers only).
 * Always syncs ALL trade customers (B2B priority), with optional limit on non-trade pages.
 *
 * Uses generator-based pagination for memory efficiency with ~60k customers.
 *
 * Batching strategy:
 * - API returns ~100 customers per page
 * - Buffer 10 pages (~1000 customers) before DB write
 * - Reduces DB round-trips while keeping memory bounded
 *
 * Usage:
 * - Full sync (null): Weekly job syncing all ~68k customers (~45 min)
 * - Quick sync (5): Hourly job catching recent signups (~500 customers, ~1 min)
 */
final readonly class SyncCustomersUseCase
{
    /**
     * Number of pages to buffer before writing to database.
     * 10 pages × ~100 customers/page = ~1000 customers per batch.
     */
    private const int PAGES_PER_BATCH = 10;

    /**
     * Log progress every N batches at info level.
     * 10 batches × ~1000 customers/batch = ~10,000 customers between progress logs.
     */
    private const int PROGRESS_LOG_INTERVAL = 10;

    public function __construct(
        private CustomerClientInterface $customerClient,
        private CustomerRepositoryInterface $customerRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize customers from ShopWired API to local database.
     *
     * Iterates through customer pages, buffering PAGES_PER_BATCH pages
     * before flushing to database. Uses continue-on-failure semantics:
     * individual save failures are logged and counted, but processing continues.
     *
     * @param int|null $maxTradePages Max trade pages (null = all ~5 pages, 1 page ≈ 100 customers)
     * @param int|null $maxNonTradePages Max non-trade pages (null = all ~677 pages, 1 page ≈ 100 customers)
     *
     * @return SyncResult Results with fetched/saved/failed counts
     *
     * @throws AuthenticationExpiredException When ShopWired credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When ShopWired API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    public function execute(
        ?int $maxTradePages = null,
        ?int $maxNonTradePages = null,
    ): SyncResult {
        $syncType = match (true) {
            $maxTradePages === null && $maxNonTradePages === null => 'full',
            default => \sprintf('quick (%s trade, %s non-trade pages)', $maxTradePages ?? 'all', $maxNonTradePages ?? 'all'),
        };
        $this->logger->info("Starting {$syncType} customer sync from ShopWired");

        $totalFetched = 0;
        $totalSaved = 0;
        $totalFailed = 0;
        /** @var list<int> $allFailedReferences */
        $allFailedReferences = [];

        /** @var list<Customer> $buffer */
        $buffer = [];
        $pagesBuffered = 0;
        $batchesFlushed = 0;

        foreach ($this->customerClient->iterateCustomerBatches($maxTradePages, $maxNonTradePages) as $pageNumber => $customers) {
            $totalFetched += \count($customers);
            $buffer = [...$buffer, ...$customers];
            $pagesBuffered++;

            $this->logger->debug('Fetched customer page from API', [
                'page' => $pageNumber,
                'count' => \count($customers),
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
                    $this->logger->info('Customer sync progress', [
                        'fetched' => $totalFetched,
                        'saved' => $totalSaved,
                        'failed' => $totalFailed,
                    ]);
                }
            }
        }

        // Flush remaining customers in buffer
        if ($buffer !== []) {
            $result = $this->flushBuffer($buffer, 'final');
            $totalSaved += $result->saved;
            $totalFailed += $result->failed;
            $allFailedReferences = [...$allFailedReferences, ...$result->failedReferences];
        }

        if ($totalFetched === 0) {
            $this->logger->info('Customer sync completed: no customers found in ShopWired');

            return SyncResult::empty();
        }

        $this->logger->info('Customer sync completed', [
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
     * Flush buffered customers to database.
     *
     * @param list<Customer> $customers Customers to save
     * @param int|string $batchIdentifier For logging (page number or 'final')
     */
    private function flushBuffer(array $customers, int|string $batchIdentifier): SyncResult
    {
        $this->logger->debug('Flushing customer batch to database', [
            'batch' => $batchIdentifier,
            'count' => \count($customers),
        ]);

        $saveResult = $this->customerRepository->saveMany($customers);

        if ($saveResult->hasFailures()) {
            $this->logger->error('Failed to save some customers to database', [
                'batch' => $batchIdentifier,
                'failed_count' => $saveResult->failed,
                'failed_ids' => $saveResult->failedReferences,
            ]);
        }

        return new SyncResult(
            fetched: \count($customers),
            saved: $saveResult->succeeded,
            failed: $saveResult->failed,
            failedReferences: $saveResult->failedReferences,
        );
    }
}
