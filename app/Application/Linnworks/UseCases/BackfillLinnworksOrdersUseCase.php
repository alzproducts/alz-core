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
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Backfill historical Linnworks orders from pre-fetched order IDs.
 *
 * Accepts order IDs (typically from the Dashboards SQL API), fetches
 * full orders via the v2 REST endpoint's `id` parameter (which bypasses
 * the ~30-day fromDate limit), and persists them via buffer/flush.
 *
 * Pattern: follows SyncLinnworksOrdersUseCase — iterate Generator,
 * buffer chunks, flush batches, continue-on-failure.
 */
final readonly class BackfillLinnworksOrdersUseCase
{
    /**
     * Number of chunks to buffer before writing to database.
     * 5 chunks × ~80 orders/chunk = ~400 orders per batch.
     */
    private const int CHUNKS_PER_BATCH = 5;

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
     * Backfill orders for the given IDs.
     *
     * @param list<Guid> $orderIds Pre-fetched order IDs to backfill
     *
     * @throws AuthenticationExpiredException When Linnworks credentials invalid/expired
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When requested resource not found (404)
     * @throws ExternalServiceUnavailableException When Linnworks API unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     */
    public function execute(array $orderIds): SyncResult
    {
        if ($orderIds === []) {
            $this->logger->info('Linnworks order backfill skipped: no order IDs provided');

            return SyncResult::empty();
        }

        $this->logger->info('Linnworks order backfill starting', [
            'total_ids' => \count($orderIds),
        ]);

        return $this->fetchAndSaveOrders($orderIds);
    }

    /**
     * Iterate order IDs in chunks, buffer fetched orders, and flush to database.
     *
     * @param list<Guid> $orderIds
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     */
    private function fetchAndSaveOrders(array $orderIds): SyncResult
    {
        $totals = new BackfillTotalsResult();
        /** @var list<LinnworksOrder> $buffer */
        [$buffer, $chunksBuffered, $batchesFlushed] = [[], 0, 0];
        /** @var list<LinnworksOrder> $orders */
        foreach ($this->orderClient->iterateOrdersByIds($orderIds) as $chunkIndex => $orders) {
            $totals->addFetched(\count($orders));
            \array_push($buffer, ...$orders);
            $chunksBuffered++;
            $this->logChunkFetched($chunkIndex, \count($orders), \count($buffer));
            if ($chunksBuffered >= self::CHUNKS_PER_BATCH) {
                $totals->accumulateFlush($this->flushBuffer($buffer, $batchesFlushed));
                [$buffer, $chunksBuffered] = [[], 0];
                self::releaseMemory();
                $this->logProgressIfDue(++$batchesFlushed, $totals);
            }
        }

        return $this->finalize($totals, $buffer, \count($orderIds));
    }

    /**
     * Flush any remaining buffer and build the final SyncResult.
     *
     * @param list<LinnworksOrder> $buffer
     *
     * @throws ExternalServiceUnavailableException
     */
    private function finalize(BackfillTotalsResult $totals, array $buffer, int $totalIds): SyncResult
    {
        if ($buffer !== []) {
            $totals->accumulateFlush($this->flushBuffer($buffer, 'final'));
        }

        $this->logger->info('Linnworks order backfill completed', [
            'total_ids' => $totalIds,
            ...$totals->toLogContext(),
            'memory_current_mb' => \round(\memory_get_usage(false) / 1048576, 1),
            'memory_peak_mb' => \round(\memory_get_peak_usage(true) / 1048576, 1),
        ]);

        return $totals->toSyncResult();
    }

    /**
     * Log a debug message for a fetched chunk.
     */
    private function logChunkFetched(int $chunkIndex, int $count, int $bufferSize): void
    {
        $this->logger->debug('Fetched order chunk by IDs', [
            'chunk' => $chunkIndex,
            'count' => $count,
            'buffer_size' => $bufferSize,
        ]);
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
     * Log progress at info level every N batches.
     */
    private function logProgressIfDue(int $batchesFlushed, BackfillTotalsResult $totals): void
    {
        if ($batchesFlushed % self::PROGRESS_LOG_INTERVAL === 0) {
            $this->logger->info('Linnworks order backfill progress', [
                ...$totals->toLogContext(),
                'memory_current_mb' => \round(\memory_get_usage(false) / 1048576, 1),
                'memory_peak_mb' => \round(\memory_get_peak_usage(true) / 1048576, 1),
            ]);
        }
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
        $this->logger->debug('Flushing backfill batch to database', [
            'batch' => $batchIdentifier,
            'count' => \count($orders),
        ]);

        $saveResult = $this->orderRepository->saveMany($orders);

        if ($saveResult->hasFailures()) {
            $this->logger->error('Failed to save some backfill orders to database', [
                'batch' => $batchIdentifier,
                'failed_count' => $saveResult->failed,
                'failed_ids' => $saveResult->failedReferences,
            ]);
        }

        return $saveResult;
    }
}
