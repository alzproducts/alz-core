<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UpdateCostPriceBySupplier;

use App\Application\Catalog\Results\CostPriceUpdateResult;
use App\Application\Catalog\Results\FailedCostPriceUpdateResult;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Linnworks\LinnworksSyncDispatcherInterface;
use App\Application\Contracts\Linnworks\StockItemSupplierRepositoryInterface;
use App\Application\Linnworks\Resolvers\SupplierGuidResolver;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\Validators\SkuSupplierLinkValidator;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\Inventory\ValueObjects\StockItemSupplierStat;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;
use Webmozart\Assert\Assert;

/**
 * Bulk update product cost prices for a shared supplier.
 *
 * Orchestrates:
 * 1. Pre-flight validation: ensure all SKUs have the specified supplier linked
 * 2. Resolve SKUs → stockItemIds (1 API call)
 * 3. Partition resolved vs unresolved SKUs
 * 4. Resolve supplier name → GUID (1 API call)
 * 5. Fetch existing supplier stats for resolved items (1 API call, read-modify-write)
 * 6. Merge new purchase prices into fetched stats
 * 7. Send complete 15-field supplier stat objects (1 API call)
 * 8. Best-effort local DB updates for succeeded items
 */
final readonly class UpdateCostPriceBySupplierUseCase
{
    public function __construct(
        private InventoryClientInterface $inventoryClient,
        private InventoryUpdateClientInterface $inventoryUpdateClient,
        private StockItemSupplierRepositoryInterface $supplierRepository,
        private SupplierGuidResolver $supplierGuidResolver,
        private LinnworksSyncDispatcherInterface $syncDispatcher,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param list<UpdateCostPriceCommand> $commands
     *
     * @throws ValidationFailedException When any SKU lacks the specified supplier
     * @throws ResourceNotFoundException When supplier not found in Linnworks
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException On local DB query failure
     * @throws DuplicateRecordException On local DB constraint violation
     */
    public function execute(string $supplierName, array $commands): CostPriceUpdateResult
    {
        Assert::notEmpty($commands, 'At least one cost price command is required');

        $this->logStart($supplierName, $commands);
        $this->runPreFlightValidation($supplierName, $commands);
        [$result, $skuToGuid] = $this->performBulkUpdate($supplierName, $commands);
        $result = $this->updateLocalDatabase($supplierName, $commands, $result, $skuToGuid);
        $this->dispatchReconciliationSyncs($result);
        $this->logResult($result);

        return $result;
    }

    /**
     * Resolve identifiers, fetch existing stats, merge prices, and send complete objects.
     *
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     *
     * @return array{CostPriceUpdateResult, array<string, Guid>}
     *
     * @throws ResourceNotFoundException When supplier not found in Linnworks
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private function performBulkUpdate(string $supplierName, array $commands): array
    {
        [$resolved, $mergedStats, $allFailures, $skuToGuid] = $this->resolveAndMerge($supplierName, $commands);

        if ($resolved === [] || $mergedStats === []) {
            return [new CostPriceUpdateResult(\count($commands), 0, $allFailures), $skuToGuid];
        }

        $apiError = $this->tryUpdateStats($mergedStats);

        if ($apiError !== null) {
            $this->logBulkApiFailure($supplierName, \count($mergedStats), $apiError);
            $apiFailures = CostPriceBySupplierTransformer::buildApiFailures($resolved, 'Linnworks API error: ' . $apiError->getMessage(), $skuToGuid);

            return [new CostPriceUpdateResult(\count($commands), 0, [...$allFailures, ...$apiFailures]), $skuToGuid];
        }

        return [new CostPriceUpdateResult(\count($commands), \count($mergedStats), $allFailures), $skuToGuid];
    }

    /**
     * Resolve SKUs, fetch supplier stats, and merge new prices.
     *
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     *
     * @return array{list<UpdateCostPriceCommand>, list<StockItemSupplierStat>, list<FailedCostPriceUpdateResult>, array<string, Guid>}
     *
     * @throws InvalidApiRequestException
     * @throws InvalidApiResponseException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws ResourceNotFoundException
     */
    private function resolveAndMerge(string $supplierName, array $commands): array
    {
        $skuToGuid = $this->inventoryClient->resolveStockItemIds(CostPriceBySupplierTransformer::extractUniqueSkus($commands));
        [$resolved, $failures] = CostPriceBySupplierTransformer::partitionByResolution($commands, $skuToGuid);

        if ($resolved === []) {
            return [$resolved, [], $failures, $skuToGuid];
        }

        $supplierGuid = $this->supplierGuidResolver->resolve($supplierName);
        $stockItemGuids = CostPriceBySupplierTransformer::extractStockItemGuids($resolved, $skuToGuid);
        $statsByStockItem = $this->inventoryClient->getStockSupplierStatsBulk($stockItemGuids);

        [$mergedStats, $mergeFailures] = CostPriceBySupplierTransformer::mergeSupplierPrices($resolved, $skuToGuid, $supplierGuid, $statsByStockItem);

        return [$resolved, $mergedStats, [...$failures, ...$mergeFailures], $skuToGuid];
    }

    /**
     * Attempt the bulk supplier stats update, returning any API exception or null on success.
     *
     * @param list<StockItemSupplierStat> $mergedStats
     */
    private function tryUpdateStats(array $mergedStats): ?AbstractApiException
    {
        try {
            $this->inventoryUpdateClient->updateStockSupplierStats($mergedStats);

            return null;
        } catch (AbstractApiException $e) {
            return $e;
        }
    }

    private function logBulkApiFailure(string $supplierName, int $resolvedCount, AbstractApiException $e): void
    {
        $this->logger->warning('Bulk supplier price update API call failed', [
            'supplier' => $supplierName,
            'resolved_count' => $resolvedCount,
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Fail-fast: reject entire batch if any SKU doesn't have the supplier linked.
     *
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     *
     * @throws ValidationFailedException When any SKU lacks the specified supplier
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function runPreFlightValidation(string $supplierName, array $commands): void
    {
        $uniqueSkus = \array_values(\array_unique(\array_map(
            static fn(UpdateCostPriceCommand $c): string => $c->sku->value,
            $commands,
        )));

        $suppliersBySku = $this->supplierRepository->getSuppliersBySkus($uniqueSkus);

        (new SkuSupplierLinkValidator($commands, $supplierName, $suppliersBySku))->validate()->orFail();
    }

    /**
     * Update local DB for succeeded items.
     *
     * On DB failure, all previously succeeded items are marked as failed in the
     * result — the Linnworks update went through but the local DB doesn't reflect
     * it, so the frontend would show stale data.
     *
     * @param list<UpdateCostPriceCommand> $commands
     * @param array<string, Guid> $skuToGuid
     */
    private function updateLocalDatabase(
        string $supplierName,
        array $commands,
        CostPriceUpdateResult $result,
        array $skuToGuid,
    ): CostPriceUpdateResult {
        $purchasePricesBySku = CostPriceBySupplierTransformer::buildSucceededPriceMap($commands, $result);

        if ($purchasePricesBySku === []) {
            return $result;
        }

        try {
            $this->supplierRepository->bulkUpdatePurchasePrices($supplierName, $purchasePricesBySku);

            return $result;
        } catch (DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException $e) {
            return $this->handleDbWriteFailure($commands, $result, $skuToGuid, $e);
        }
    }

    /**
     * Log the DB failure and mark all items as failed.
     *
     * @param list<UpdateCostPriceCommand> $commands
     * @param array<string, Guid> $skuToGuid
     */
    private function handleDbWriteFailure(
        array $commands,
        CostPriceUpdateResult $result,
        array $skuToGuid,
        DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException $e,
    ): CostPriceUpdateResult {
        $this->logger->error('Failed to update local DB for cost prices — frontend will show stale data', [
            'count' => $result->succeeded,
            'error' => $e->getMessage(),
        ]);

        $dbFailures = CostPriceBySupplierTransformer::buildApiFailures($commands, 'Local DB update failed: ' . $e->getMessage(), $skuToGuid);

        return new CostPriceUpdateResult($result->total, 0, $dbFailures);
    }

    /**
     * Dispatch a stock item sync for each failed item that has a resolved stock item ID.
     *
     * This ensures eventual consistency — even if the local DB write failed,
     * the sync job will pull fresh data from Linnworks within minutes.
     */
    private function dispatchReconciliationSyncs(CostPriceUpdateResult $result): void
    {
        foreach ($result->failures as $failure) {
            if ($failure->stockItemId !== null) {
                $this->syncDispatcher->dispatchStockItemSync($failure->stockItemId);
            }
        }
    }

    /**
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     */
    private function logStart(string $supplierName, array $commands): void
    {
        $this->logger->info('Bulk updating cost prices', [
            'count' => \count($commands),
            'supplier_name' => $supplierName,
        ]);
    }

    private function logResult(CostPriceUpdateResult $result): void
    {
        $this->logger->info('Bulk cost price update complete', [
            'total' => $result->total,
            'succeeded' => $result->succeeded,
            'failed' => \count($result->failures),
        ]);
    }
}
