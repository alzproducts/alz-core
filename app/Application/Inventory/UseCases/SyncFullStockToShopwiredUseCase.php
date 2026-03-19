<?php

declare(strict_types=1);

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\Inventory\ProductStockRepositoryInterface;
use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Inventory\Enums\LockName;
use App\Application\Results\StockUpdateResult;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use Psr\Log\LoggerInterface;

/**
 * Synchronise all stock levels from Linnworks to ShopWired.
 *
 * Fetches the full stock catalogue from Linnworks and compares it against the
 * local ShopWired DB snapshot. Only SKUs where levels differ are pushed to the
 * ShopWired API and the local DB is updated to reflect the new state.
 *
 * Acquires a blocking lock shared with SyncDeltaStockToShopwiredUseCase so that
 * full and delta syncs cannot run concurrently (preventing stock ping-pong).
 * The Linnworks fetch happens outside the lock — only the local DB read,
 * ShopWired push, and local DB write are protected.
 *
 * @see InventoryScheduleServiceProvider for schedule frequency.
 */
final readonly class SyncFullStockToShopwiredUseCase
{
    private const int LOCK_TIMEOUT_SECONDS = 90;

    public function __construct(
        private StockDashboardsClientInterface $linnworksClient,
        private ProductStockRepositoryInterface $stockRepository,
        private StockClientInterface $shopwiredClient,
        private LockManagerInterface $lockManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Execute the full stock sync.
     *
     * @throws LockAcquisitionException When lock cannot be acquired within timeout
     * @throws AbstractApiException Re-thrown from partial batch transport failure (TransientApiFailure → retry, PermanentApiFailure → fail)
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws DatabaseOperationFailedException When local DB operations fail
     * @throws DuplicateRecordException When a unique constraint violation occurs
     */
    public function execute(): void
    {
        $this->logger->info('Full stock sync: starting');

        // Linnworks is a read-only source here — fetch outside the lock.
        $linnworksStock = $this->linnworksClient->getAllStockLevels();

        $this->logger->debug('Full stock sync: fetched from Linnworks', [
            'linnworks_count' => \count($linnworksStock),
        ]);

        // Lock covers: local DB read → diff → ShopWired push → local DB write.
        // Logging happens after the lock is released.
        /** @var array{toUpdate: list<ItemStockLevel>, result: StockUpdateResult|null, local_count: int} $outcome */
        $outcome = $this->lockManager->withLock(
            LockName::StockSync->value,
            self::LOCK_TIMEOUT_SECONDS,
            fn(): array => $this->syncUnderLock($linnworksStock),
        );

        $toUpdate = $outcome['toUpdate'];
        $result = $outcome['result'];
        $localCount = $outcome['local_count'];

        if ($toUpdate === []) {
            $this->logger->info('Full stock sync: no differences found', [
                'linnworks_count' => \count($linnworksStock),
                'local_count' => $localCount,
            ]);

            return;
        }

        $this->logger->info('Full stock sync: completed', [
            'linnworks_count' => \count($linnworksStock),
            'local_count' => $localCount,
            'attempted' => \count($toUpdate),
            'pushed' => $result !== null ? \count($result->pushed) : 0,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Private Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Critical section: read local stock, compute diff, push to ShopWired, update local DB.
     *
     * Called exclusively under the stock-sync lock to prevent concurrent full/delta syncs
     * from overwriting each other's updates.
     *
     * @param list<ItemStockLevel> $linnworksStock
     *
     * @return array{toUpdate: list<ItemStockLevel>, result: StockUpdateResult|null, local_count: int}
     *
     * @throws AbstractApiException Re-thrown from partial batch transport failure (TransientApiFailure → retry, PermanentApiFailure → fail)
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    private function syncUnderLock(array $linnworksStock): array
    {
        $localStock = $this->stockRepository->getAllStockLevels();
        $localCount = \count($localStock);
        $toUpdate = self::findDifferences($linnworksStock, $localStock);

        if ($toUpdate === []) {
            return ['toUpdate' => [], 'result' => null, 'local_count' => $localCount];
        }

        $result = $this->shopwiredClient->updateStockQuantity($toUpdate);

        // Always update local DB for items that made it through
        if ($result->pushed !== []) {
            $this->stockRepository->updateStockLevels($result->pushed);
        }

        // Re-throw first transport failure after local DB is updated — job will retry
        // and only items from failed batches will still appear as diffs.
        if ($result->transportFailures !== []) {
            $this->logger->warning('Full stock sync: batch transport failure, local DB updated for pushed items', [
                'pushed_count' => \count($result->pushed),
                'total_attempted' => \count($toUpdate),
                'failure_count' => \count($result->transportFailures),
            ]);

            throw $result->transportFailures[0];
        }

        return ['toUpdate' => $toUpdate, 'result' => $result, 'local_count' => $localCount];
    }

    /**
     * Find Linnworks items whose stock level differs from the local DB snapshot.
     *
     * Only SKUs present in both Linnworks and the local DB are compared.
     * SKUs exclusive to Linnworks (not yet in ShopWired) are silently skipped.
     *
     * @param list<ItemStockLevel> $linnworksStock
     * @param list<ItemStockLevel> $localStock
     *
     * @return list<ItemStockLevel>
     */
    private static function findDifferences(array $linnworksStock, array $localStock): array
    {
        $localMap = [];

        foreach ($localStock as $item) {
            $localMap[$item->sku->value] = $item->quantity;
        }

        $differences = [];

        foreach ($linnworksStock as $item) {
            if (isset($localMap[$item->sku->value]) && $localMap[$item->sku->value] !== $item->quantity) {
                $differences[] = $item;
            }
        }

        return $differences;
    }
}
