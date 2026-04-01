<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UpdateCostPrice;

use App\Application\Catalog\Results\CostPriceUpdateResult;
use App\Application\Contracts\Catalog\ProductSupplierLookupInterface;
use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Catalog\Product\Validators\SkuSupplierLinkValidator;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\ValidationFailedException;
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
 * 5. Bulk update supplier purchase prices (1 API call)
 * 6. Best-effort local DB updates for succeeded items
 */
final readonly class UpdateCostPriceUseCase
{
    public function __construct(
        private InventoryClientInterface $inventoryClient,
        private InventoryUpdateClientInterface $inventoryUpdateClient,
        private StockItemRepositoryInterface $stockItemRepository,
        private ProductSupplierLookupInterface $supplierLookup,
        private SupplierGuidResolver $supplierGuidResolver,
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
    public function execute(array $commands): CostPriceUpdateResult
    {
        Assert::notEmpty($commands, 'At least one cost price command is required');

        $this->logStart($commands);
        $this->runPreFlightValidation($commands);
        $result = $this->performBulkUpdate($commands);
        $this->updateLocalDatabase($commands, $result);
        $this->logResult($result);

        return $result;
    }

    /**
     * Resolve identifiers, partition, and send bulk API update.
     *
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     *
     * @throws ResourceNotFoundException When supplier not found in Linnworks
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private function performBulkUpdate(array $commands): CostPriceUpdateResult
    {
        $skuToGuid = $this->inventoryClient->resolveStockItemIds(CostPriceUpdateTransformer::extractUniqueSkus($commands));
        [$resolved, $failures] = CostPriceUpdateTransformer::partitionByResolution($commands, $skuToGuid);

        if ($resolved === []) {
            return new CostPriceUpdateResult(\count($commands), 0, $failures);
        }

        $supplierGuid = $this->supplierGuidResolver->resolve($resolved[0]->supplierName);
        $this->inventoryUpdateClient->updateBulkSupplierStats($supplierGuid, CostPriceUpdateTransformer::buildPriceMap($resolved, $skuToGuid));

        return new CostPriceUpdateResult(\count($commands), \count($resolved), $failures);
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
    private function runPreFlightValidation(array $commands): void
    {
        $suppliersBySku = [];

        foreach ($commands as $command) {
            $sku = $command->sku->value;
            if (! isset($suppliersBySku[$sku])) {
                $suppliersBySku[$sku] = $this->supplierLookup->getByProductSku($sku);
            }
        }

        (new SkuSupplierLinkValidator($commands, $suppliersBySku))->validate()->orFail();
    }

    /**
     * Best-effort local DB updates for succeeded items.
     *
     * @param list<UpdateCostPriceCommand> $commands
     */
    private function updateLocalDatabase(array $commands, CostPriceUpdateResult $result): void
    {
        $failedSkus = CostPriceUpdateTransformer::buildFailedSkuLookup($result);

        foreach ($commands as $command) {
            if (isset($failedSkus[$command->sku->value])) {
                continue;
            }

            $this->updateSingleLocalRecord($command);
        }
    }

    private function updateSingleLocalRecord(UpdateCostPriceCommand $command): void
    {
        try {
            $this->stockItemRepository->updateSupplierPurchasePrice(
                $command->sku,
                $command->supplierName,
                $command->costPrice->toNet(),
            );
        } catch (DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException $e) {
            $this->logger->warning('Failed to update local DB for cost price', [
                'sku' => $command->sku->value,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @param non-empty-list<UpdateCostPriceCommand> $commands
     */
    private function logStart(array $commands): void
    {
        $this->logger->info('Bulk updating cost prices', [
            'count' => \count($commands),
            'supplier_name' => $commands[0]->supplierName,
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
