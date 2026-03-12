<?php

declare(strict_types=1);

namespace App\Application\Inventory\UseCases;

use App\Application\Contracts\Inventory\ProductStockRepositoryInterface;
use App\Application\Contracts\Linnworks\StockDashboardsClientInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\Shopwired\StockClientInterface;
use App\Application\Inventory\Enums\LockName;
use App\Application\Results\StockUpdateResult;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;
use Psr\Log\LoggerInterface;
use RuntimeException;

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
 * Intended frequency: every 15 minutes.
 */
final readonly class SyncFullStockToShopwiredUseCase
{
    private const int LOCK_TIMEOUT_SECONDS = 120;

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
     * @throws AuthenticationExpiredException When Linnworks or ShopWired credentials invalid
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws ResourceNotFoundException When a resource is not found (404)
     * @throws ExternalServiceUnavailableException When either API or DB is unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws DatabaseOperationFailedException When local DB operations fail
     * @throws DuplicateRecordException When a unique constraint violation occurs
     * @throws RuntimeException When HTTP pool initialisation fails
     */
    public function execute(): void
    {
        $this->logger->info('Full stock sync: starting');

        // Linnworks is a read-only source here — fetch outside the lock.
        $linnworksStock = $this->linnworksClient->getAllStockLevels();

        // Lock covers: local DB read → diff → ShopWired push → local DB write.
        // Logging happens after the lock is released.
        /** @var array{toUpdate: list<ItemStockLevel>, result: StockUpdateResult|null} $outcome */
        $outcome = $this->lockManager->withLock(
            LockName::StockSync->value,
            self::LOCK_TIMEOUT_SECONDS,
            fn(): array => $this->syncUnderLock($linnworksStock),
        );

        $toUpdate = $outcome['toUpdate'];
        $result = $outcome['result'];

        if ($toUpdate === []) {
            $this->logger->info('Full stock sync: no differences found', [
                'linnworks_count' => \count($linnworksStock),
            ]);

            return;
        }

        if ($result?->hasFailures() === true) {
            $this->logger->warning('Full stock sync: some SKUs failed to update', [
                'failed_skus' => \array_map(static fn(ItemStockLevel $i): string => $i->sku->value, $result->failed),
                'failed_count' => \count($result->failed),
            ]);
        }

        $this->logger->info('Full stock sync: completed', [
            'linnworks_count' => \count($linnworksStock),
            'attempted' => \count($toUpdate),
            'succeeded' => $result !== null ? \count($result->succeeded) : 0,
            'failed' => $result !== null ? \count($result->failed) : 0,
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
     * @return array{toUpdate: list<ItemStockLevel>, result: StockUpdateResult|null}
     *
     * @throws AuthenticationExpiredException
     * @throws InvalidApiRequestException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws RuntimeException
     */
    private function syncUnderLock(array $linnworksStock): array
    {
        $localStock = $this->stockRepository->getAllStockLevels();
        $toUpdate = self::findDifferences($linnworksStock, $localStock);

        if ($toUpdate === []) {
            return ['toUpdate' => [], 'result' => null];
        }

        $result = $this->shopwiredClient->updateStockQuantity($toUpdate);

        if ($result->succeeded !== []) {
            $this->stockRepository->updateStockLevels($result->succeeded);
        }

        return ['toUpdate' => $toUpdate, 'result' => $result];
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
