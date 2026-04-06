<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Contracts\Linnworks\LinnworksOrderRepositoryInterface;
use App\Application\Contracts\Linnworks\OrderClientInterface;
use App\Application\Results\SaveManyResult;
use App\Application\Results\SyncResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\ValueObjects\LinnworksOrder;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Core order sync logic shared by all 5 tiers.
 *
 * Iterates batches from the OrderClient Generator, buffers pages,
 * and flushes via per-order transactional save. Tracks the max
 * LastUpdated across all orders for cursor advancement.
 *
 * Pattern: follows SyncAllStockItemsUseCase — iterate Generator,
 * buffer pages, flush batches, continue-on-failure.
 */
final readonly class SyncLinnworksOrdersUseCase
{
    /**
     * Number of pages to buffer before writing to database.
     * 5 pages × ~200 orders/page = ~1000 orders per batch.
     */
    private const int PAGES_PER_BATCH = 5;

    /**
     * Log progress every N batches at info level.
     */
    private const int PROGRESS_LOG_INTERVAL = 5;

    public function __construct(
        private OrderClientInterface $orderClient,
        private LinnworksOrderRepositoryInterface $orderRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Synchronize orders updated since fromDate to local database.
     *
     * @return SyncResult Results with fetched/saved/failed counts and latestLastUpdated
     *
     * @throws AuthenticationExpiredException When Linnworks credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When Linnworks API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    public function execute(DateTimeImmutable $fromDate): SyncResult
    {
        $this->logger->info('Linnworks order sync starting', [
            'from_date' => $fromDate->format('Y-m-d H:i:s'),
        ]);

        $totalFetched = 0;
        $totalSaved = 0;
        $totalFailed = 0;
        /** @var list<string> $allFailedReferences */
        $allFailedReferences = [];
        $latestLastUpdated = null;

        /** @var list<LinnworksOrder> $buffer */
        $buffer = [];
        $pagesBuffered = 0;
        $batchesFlushed = 0;

        /** @var list<LinnworksOrder> $orders */
        foreach ($this->orderClient->iterateOrders($fromDate) as $pageNumber => $orders) {
            $totalFetched += \count($orders);
            \array_push($buffer, ...$orders);
            $pagesBuffered++;

            // Track max LastUpdated across all orders
            $latestLastUpdated = self::maxLastUpdated($latestLastUpdated, $orders);

            $this->logger->debug('Fetched order page from API', [
                'page' => $pageNumber,
                'count' => \count($orders),
                'buffer_size' => \count($buffer),
            ]);

            if ($pagesBuffered >= self::PAGES_PER_BATCH) {
                $result = $this->flushBuffer($buffer, $pageNumber);
                $totalSaved += $result->succeeded;
                $totalFailed += $result->failed;
                \array_push($allFailedReferences, ...$result->failedReferences);

                $buffer = [];
                $pagesBuffered = 0;
                self::releaseMemory();
                $batchesFlushed++;

                if ($batchesFlushed % self::PROGRESS_LOG_INTERVAL === 0) {
                    $this->logger->info('Linnworks order sync progress', [
                        'fetched' => $totalFetched,
                        'saved' => $totalSaved,
                        'failed' => $totalFailed,
                        'memory_current_mb' => \round(\memory_get_usage(false) / 1048576, 1),
                        'memory_peak_mb' => \round(\memory_get_peak_usage(true) / 1048576, 1),
                    ]);
                }
            }
        }

        // Flush remaining items in buffer
        if ($buffer !== []) {
            $result = $this->flushBuffer($buffer, 'final');
            $totalSaved += $result->succeeded;
            $totalFailed += $result->failed;
            \array_push($allFailedReferences, ...$result->failedReferences);
        }

        if ($totalFetched === 0) {
            $this->logger->info('Linnworks order sync completed: no orders found', [
                'from_date' => $fromDate->format('Y-m-d H:i:s'),
            ]);

            return SyncResult::empty();
        }

        $this->logger->info('Linnworks order sync completed', [
            'fetched' => $totalFetched,
            'saved' => $totalSaved,
            'failed' => $totalFailed,
            'latest_last_updated' => $latestLastUpdated?->format('Y-m-d H:i:s'),
            'memory_current_mb' => \round(\memory_get_usage(false) / 1048576, 1),
            'memory_peak_mb' => \round(\memory_get_peak_usage(true) / 1048576, 1),
        ]);

        return new SyncResult(
            fetched: $totalFetched,
            saved: $totalSaved,
            failed: $totalFailed,
            latestLastUpdated: $latestLastUpdated,
            failedReferences: $allFailedReferences,
        );
    }

    /**
     * Reclaim memory between batches: collect cyclic refs, then return freed slab pages to OS.
     */
    private static function releaseMemory(): void
    {
        \gc_collect_cycles();
        \gc_mem_caches();
    }

    /**
     * Flush buffered orders to database via per-order transactional save.
     *
     * @param list<LinnworksOrder> $orders
     *
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function flushBuffer(array $orders, int|string $batchIdentifier): SaveManyResult
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

        return $saveResult;
    }

    /**
     * Track the maximum LastUpdated across a batch of orders.
     *
     * @param list<LinnworksOrder> $orders
     */
    private static function maxLastUpdated(?DateTimeImmutable $current, array $orders): ?DateTimeImmutable
    {
        foreach ($orders as $order) {
            if ($current === null || $order->lastUpdated > $current) {
                $current = $order->lastUpdated;
            }
        }

        return $current;
    }
}
