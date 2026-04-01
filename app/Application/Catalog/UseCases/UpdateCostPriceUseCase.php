<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Linnworks\StockItemRepositoryInterface;
use App\Domain\Catalog\Product\Commands\UpdateCostPriceCommand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Update a product's cost price for a specific supplier.
 *
 * Orchestrates two operations:
 * 1. Update supplier purchase price in Linnworks via API (source of truth)
 * 2. Update local stock_item_suppliers.purchase_price for immediate consistency
 */
final readonly class UpdateCostPriceUseCase
{
    public function __construct(
        private InventoryUpdateClientInterface $inventoryUpdateClient,
        private StockItemRepositoryInterface $stockItemRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ResourceNotFoundException When stock item or supplier not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException On local DB query failure
     * @throws DuplicateRecordException On local DB constraint violation
     */
    public function execute(UpdateCostPriceCommand $command): void
    {
        $this->logger->info('Updating cost price', [
            'sku' => $command->sku->value,
            'supplier_name' => $command->supplierName,
            'cost_price' => $command->costPrice->toNet(),
        ]);

        $this->updateLinnworks($command);
        $this->updateLocalDatabase($command);

        $this->logger->info('Cost price updated', [
            'sku' => $command->sku->value,
            'supplier_name' => $command->supplierName,
        ]);
    }

    /**
     * @throws ResourceNotFoundException When stock item or supplier not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private function updateLinnworks(UpdateCostPriceCommand $command): void
    {
        $this->inventoryUpdateClient->updateSupplierPurchasePrice(
            $command->sku,
            $command->supplierName,
            $command->costPrice,
        );
    }

    /**
     * @throws DatabaseOperationFailedException On local DB query failure
     * @throws DuplicateRecordException On local DB constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    private function updateLocalDatabase(UpdateCostPriceCommand $command): void
    {
        $this->stockItemRepository->updateSupplierPurchasePrice(
            $command->sku,
            $command->supplierName,
            $command->costPrice->toNet(),
        );
    }
}
