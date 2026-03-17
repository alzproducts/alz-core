<?php

declare(strict_types=1);

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\Inventory\ProductStockRepositoryInterface;
use App\Application\Contracts\Inventory\SyncCursorRepositoryInterface;
use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Enums\SyncCursorType;
use App\Application\Inventory\DTOs\StockLevelDeltaDTO;
use App\Application\Inventory\Enums\LockName;
use App\Application\Results\StockUpdateResult;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Synchronise recently-changed stock levels from Linnworks to ShopWired.
 *
 * Queries Linnworks for SKUs whose StockLevel.LastUpdateDate is newer than
 * the stored cursor. Only SKUs where the Linnworks level differs from the
 * local ShopWired DB snapshot are pushed to the ShopWired API.
 *
 * The cursor advances to the max LastUpdateDate from the delta results after
 * each run. A short lookback cap keeps the delta lightweight — the full
 * sync handles the majority of stock updates.
 *
 * Acquires a blocking lock shared with SyncFullStockToShopwiredUseCase so
 * that full and delta syncs cannot run concurrently (preventing stock ping-pong).
 * The Linnworks fetch and pre/post processing happen outside the lock — only
 * the local DB read, ShopWired push, and local DB write are protected.
 *
 * @see InventoryScheduleServiceProvider for schedule frequency.
 */
final readonly class SyncDeltaStockToShopwiredUseCase
{
    private const int LOCK_TIMEOUT_SECONDS = 120;

    /**
     * Fallback window when no cursor exists (first run).
     */
    private const int DEFAULT_LOOKBACK_HOURS = 24;

    /**
     * Maximum lookback window when cursor is stale.
     *
     * Caps the query window to keep the delta lightweight — anything
     * older is already covered by the full sync (every 10 min).
     * The StockLevel.LastUpdateDate only tracks direct modifications
     * (booking in, scrapping, manual adjustments), so staleness is
     * expected during quiet periods.
     */
    private const int MAX_LOOKBACK_HOURS = 1;

    public function __construct(
        private StockDashboardsClientInterface $linnworksClient,
        private ProductStockRepositoryInterface $stockRepository,
        private SyncCursorRepositoryInterface $cursorRepository,
        private StockClientInterface $shopwiredClient,
        private LockManagerInterface $lockManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Execute the delta stock sync.
     *
     * @throws LockAcquisitionException When lock cannot be acquired within timeout
     * @throws AbstractApiException Re-thrown from partial batch transport failure (TransientApiFailure → retry, PermanentApiFailure → fail)
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws DatabaseOperationFailedException When local DB operations fail
     * @throws DuplicateRecordException When a unique constraint violation occurs
     */
    public function execute(): void
    {
        $storedCursor = $this->cursorRepository->getLastSyncDate(SyncCursorType::LinnworksStockDelta);
        $since = $this->resolveSince($storedCursor);

        $this->logger->info('Delta stock sync: starting', [
            'stored_cursor' => $storedCursor?->format('Y-m-d H:i:s.v'),
            'since' => $since->format('Y-m-d H:i:s.v'),
        ]);

        // Linnworks is a read-only source here — fetch outside the lock.
        $delta = $this->linnworksClient->getStockLevelsSince($since);

        if ($delta === []) {
            $this->logger->info('Delta stock sync: no changes since cursor', [
                'since' => $since->format('Y-m-d H:i:s.v'),
            ]);

            return;
        }

        // Deduplicate by SKU: keep only the last (newest) entry per SKU.
        // Linnworks can emit multiple rows for the same SKU when stock is
        // updated in rapid succession. Rows are ordered ASC, so the last
        // occurrence always holds the most recent level.
        $rawCount = \count($delta);
        $delta = self::deduplicateBySku($delta);

        $this->logger->debug('Delta stock sync: fetched from Linnworks', [
            'raw_rows' => $rawCount,
            'after_dedup' => \count($delta),
        ]);

        $skus = \array_map(static fn(StockLevelDeltaDTO $d): Sku => $d->sku, $delta);

        // Lock covers: local DB read → diff → ShopWired push → local DB write.
        // Cursor update and logging happen after the lock is released.
        /** @var array{toUpdate: list<ItemStockLevel>, result: StockUpdateResult|null} $outcome */
        $outcome = $this->lockManager->withLock(
            LockName::StockSync->value,
            self::LOCK_TIMEOUT_SECONDS,
            fn(): array => $this->syncUnderLock($delta, $skus),
        );

        // Delta rows are ordered ASC by LastUpdateDate — last element is the newest.
        // The SQL uses strict > on the cursor, so setting it to this exact value
        // means the next run fetches only rows strictly newer than this timestamp.
        // $delta is non-empty: we returned early above on empty, and deduplication
        // cannot produce empty output from non-empty input.
        /** @var non-empty-list<StockLevelDeltaDTO> $delta */
        $newCursor = $delta[\count($delta) - 1]->lastUpdateDate;
        $this->cursorRepository->updateLastSyncDate(SyncCursorType::LinnworksStockDelta, $newCursor);

        $result = $outcome['result'];
        $toUpdate = $outcome['toUpdate'];

        $this->logger->info('Delta stock sync: completed', [
            'since' => $since->format('Y-m-d H:i:s.v'),
            'delta_count' => \count($delta),
            'to_update' => \count($toUpdate),
            'pushed' => $result !== null ? \count($result->pushed) : 0,
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
     * - Cursor older than MAX_LOOKBACK_HOURS: cap to MAX_LOOKBACK_HOURS and warn
     * - Cursor within MAX_LOOKBACK_HOURS: use as-is
     */
    private function resolveSince(?DateTimeImmutable $cursor): DateTimeImmutable
    {
        if ($cursor === null) {
            return new DateTimeImmutable(\sprintf('-%d hours', self::DEFAULT_LOOKBACK_HOURS));
        }

        $maxLookback = new DateTimeImmutable(\sprintf('-%d hours', self::MAX_LOOKBACK_HOURS));

        if ($cursor < $maxLookback) {
            $this->logger->info('Delta stock sync: cursor is stale, capping lookback window', [
                'cursor' => $cursor->format('Y-m-d H:i:s'),
                'capped_to' => $maxLookback->format('Y-m-d H:i:s'),
            ]);

            return $maxLookback;
        }

        return $cursor;
    }

    /**
     * Critical section: read local stock, compute diff, push to ShopWired, update local DB.
     *
     * Called exclusively under the stock-sync lock to prevent concurrent full/delta syncs
     * from overwriting each other's updates.
     *
     * @param list<StockLevelDeltaDTO> $delta
     * @param list<Sku>                $skus
     *
     * @return array{toUpdate: list<ItemStockLevel>, result: StockUpdateResult|null}
     *
     * @throws AbstractApiException Re-thrown from partial batch transport failure (TransientApiFailure → retry, PermanentApiFailure → fail)
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    private function syncUnderLock(array $delta, array $skus): array
    {
        $localStock = $this->stockRepository->getStockLevelsBySkus($skus);
        $toUpdate = self::findDifferences($delta, $localStock);

        if ($toUpdate === []) {
            return ['toUpdate' => [], 'result' => null];
        }

        $result = $this->shopwiredClient->updateStockQuantity($toUpdate);

        // Always update local DB for items that made it through
        if ($result->pushed !== []) {
            $this->stockRepository->updateStockLevels($result->pushed);
        }

        // Re-throw transport failure after local DB is updated — job will retry
        // and only items from failed batches will still appear as diffs.
        $transportFailure = $result->transportFailure;

        if ($transportFailure !== null) {
            $this->logger->warning('Delta stock sync: batch transport failure, local DB updated for pushed items', [
                'pushed_count' => \count($result->pushed),
                'total_attempted' => \count($toUpdate),
            ]);

            throw $transportFailure;
        }

        return ['toUpdate' => $toUpdate, 'result' => $result];
    }

    /**
     * Deduplicate delta rows by SKU, keeping the last (newest) entry.
     *
     * Delta rows are ordered ASC — iterating and overwriting by SKU key
     * naturally preserves the most recent entry for each SKU.
     *
     * @param list<StockLevelDeltaDTO> $delta
     *
     * @return list<StockLevelDeltaDTO>
     */
    private static function deduplicateBySku(array $delta): array
    {
        $bySkuKey = [];

        foreach ($delta as $item) {
            $bySkuKey[$item->sku->value] = $item;
        }

        return \array_values($bySkuKey);
    }

    /**
     * Find delta items whose level differs from the local DB snapshot.
     *
     * Only SKUs present locally (i.e., in ShopWired) are compared.
     * Delta SKUs not yet in ShopWired are silently skipped.
     *
     * @param list<StockLevelDeltaDTO> $delta
     * @param list<ItemStockLevel>     $localStock
     *
     * @return list<ItemStockLevel>
     */
    private static function findDifferences(array $delta, array $localStock): array
    {
        $localMap = [];

        foreach ($localStock as $item) {
            $localMap[$item->sku->value] = $item->quantity;
        }

        $differences = [];

        foreach ($delta as $item) {
            if (isset($localMap[$item->sku->value]) && $localMap[$item->sku->value] !== $item->level) {
                $differences[] = new ItemStockLevel($item->sku, $item->level);
            }
        }

        return $differences;
    }
}
