<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Contracts\Inventory\SyncCursorRepositoryInterface;
use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Enums\SyncCursorType;
use App\Application\Jobs\Linnworks\SyncLinnworksStockItemsJob;
use App\Application\Jobs\Linnworks\SyncStockItemJob;
use App\Application\Linnworks\DTOs\ModifiedStockItemDTO;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Cursor-based incremental sync for Linnworks stock items.
 *
 * Queries Linnworks for stock items modified since the last cursor, then
 * dispatches individual SyncStockItemJob per item for async processing.
 *
 * Overflow strategy: if the SQL query hits its TOP limit (500 rows), there
 * are likely more modified items than can be efficiently fetched one-by-one.
 * Instead of dispatching 500+ individual API calls, we trigger the existing
 * bulk sync job which fetches ~200 items per page — far more efficient.
 *
 * Design tradeoff: the cursor advances after dispatching jobs, not after
 * they complete. If individual jobs fail all retries, those items are missed
 * until the daily full sync. This is an acceptable tradeoff for fire-and-forget
 * dispatch architecture — the alternative (inline processing) would block the
 * orchestrator for the duration of every API call.
 *
 * Intended frequency: every 5 minutes via SyncStockItemsWithCursorJob.
 */
final readonly class SyncStockItemWithCursorUseCase
{
    /**
     * Fallback window when no cursor exists (first run).
     */
    private const int DEFAULT_LOOKBACK_HOURS = 24;

    /**
     * Maximum lookback window when cursor is stale.
     *
     * Capped to 48 hours — the daily full sync covers anything older,
     * so a wider window just wastes query time. The extra day of leeway
     * accounts for delayed full syncs or scheduling edge cases.
     */
    private const int MAX_LOOKBACK_HOURS = 48;

    /**
     * Maximum items before triggering bulk sync instead of per-item jobs.
     *
     * Matches the SQL TOP limit in ModifiedStockItemQuery. If this many
     * rows are returned, there are likely more — bulk sync is more efficient.
     */
    private const int OVERFLOW_THRESHOLD = 500;

    public function __construct(
        private StockDashboardsClientInterface $dashboardsClient,
        private SyncCursorRepositoryInterface $cursorRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * Execute the incremental stock item sync.
     *
     * @throws AuthenticationExpiredException When Linnworks credentials invalid/expired
     * @throws ExternalServiceUnavailableException When Linnworks API or database unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ResourceNotFoundException When resource not found
     * @throws DatabaseOperationFailedException When cursor persistence fails
     */
    public function execute(): void
    {
        $storedCursor = $this->cursorRepository->getLastSyncDate(SyncCursorType::LinnworksStockItemFull);
        $since = $this->resolveSince($storedCursor);

        $this->logger->info('Stock item cursor sync: starting', [
            'stored_cursor' => $storedCursor?->format('Y-m-d H:i:s.v'),
            'since' => $since->format('Y-m-d H:i:s.v'),
        ]);

        $modifiedItems = $this->dashboardsClient->getModifiedStockItemIdsSince($since);

        if ($modifiedItems === []) {
            $this->logger->info('Stock item cursor sync: no changes since cursor', [
                'since' => $since->format('Y-m-d H:i:s.v'),
            ]);

            return;
        }

        // Overflow: SQL hit the TOP limit — trigger bulk sync instead of 500+ individual calls
        if (\count($modifiedItems) >= self::OVERFLOW_THRESHOLD) {
            $this->logger->warning('Stock item cursor sync: modified items hit threshold, triggering full sync', [
                'count' => \count($modifiedItems),
                'threshold' => self::OVERFLOW_THRESHOLD,
            ]);

            SyncLinnworksStockItemsJob::dispatch();

            $this->cursorRepository->updateLastSyncDate(
                SyncCursorType::LinnworksStockItemFull,
                new DateTimeImmutable('now'),
            );

            return;
        }

        // Normal path: dispatch per-item sync jobs
        foreach ($modifiedItems as $item) {
            SyncStockItemJob::dispatch($item->stockItemId);
        }

        // Rows ordered ASC — last element holds the newest ModifiedDate.
        // Strict > in the SQL means next run fetches only strictly newer rows.
        /** @var non-empty-list<ModifiedStockItemDTO> $modifiedItems */
        $newCursor = $modifiedItems[\count($modifiedItems) - 1]->modifiedDate;
        $this->cursorRepository->updateLastSyncDate(SyncCursorType::LinnworksStockItemFull, $newCursor);

        $this->logger->info('Stock item cursor sync: completed', [
            'dispatched' => \count($modifiedItems),
            'new_cursor' => $newCursor->format('Y-m-d H:i:s.v'),
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Resolve the effective lookback start time.
     *
     * - No cursor (first run): look back DEFAULT_LOOKBACK_HOURS
     * - Cursor older than MAX_LOOKBACK_HOURS: cap and warn
     * - Cursor within window: use as-is
     */
    private function resolveSince(?DateTimeImmutable $cursor): DateTimeImmutable
    {
        if ($cursor === null) {
            return new DateTimeImmutable(\sprintf('-%d hours', self::DEFAULT_LOOKBACK_HOURS));
        }

        $maxLookback = new DateTimeImmutable(\sprintf('-%d hours', self::MAX_LOOKBACK_HOURS));

        if ($cursor < $maxLookback) {
            $this->logger->warning('Stock item cursor sync: cursor is stale, capping lookback window', [
                'cursor' => $cursor->format('Y-m-d H:i:s'),
                'capped_to' => $maxLookback->format('Y-m-d H:i:s'),
            ]);

            return $maxLookback;
        }

        return $cursor;
    }
}
