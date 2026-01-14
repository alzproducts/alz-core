<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\CustomerClientInterface;
use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Shopwired\ValueObjects\SyncResult;
use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiRequestException;
use App\Domain\Exceptions\InvalidApiResponseException;
use App\Domain\Exceptions\ResourceNotFoundException;
use Psr\Log\LoggerInterface;

/**
 * Orchestrate full customer synchronization from ShopWired API to local database.
 *
 * Unlike orders (date-range filtered), customers require full-sync approach.
 * Uses generator-based pagination for memory efficiency with ~60k customers.
 *
 * Batching strategy:
 * - API returns ~100 customers per page
 * - Buffer 10 pages (~1000 customers) before DB write
 * - Reduces DB round-trips while keeping memory bounded
 *
 * Typical usage: weekly scheduled job (Sunday 3am) with overlap protection.
 */
final readonly class SyncAllCustomersUseCase
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
     * Synchronize all customers from ShopWired API to local database.
     *
     * Iterates through all customer pages, buffering PAGES_PER_BATCH pages
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
        $this->logger->info('Starting full customer sync from ShopWired');

        $totalFetched = 0;
        $totalSaved = 0;
        $totalFailed = 0;
        /** @var list<int> $allFailedReferences */
        $allFailedReferences = [];

        /** @var list<Customer> $buffer */
        $buffer = [];
        $pagesBuffered = 0;
        $batchesFlushed = 0;

        foreach ($this->customerClient->iterateAllCustomerBatches() as $pageNumber => $customers) {
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
