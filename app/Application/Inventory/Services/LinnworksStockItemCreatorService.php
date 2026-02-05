<?php

declare(strict_types=1);

namespace App\Application\Inventory\Services;

use App\Application\Contracts\Linnworks\InventoryClientInterface;
use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\LockManagerInterface;
use App\Application\Inventory\Enums\LockName;
use App\Application\Inventory\Params\CreateStockItemParams;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Inventory\Commands\AddInventoryItemCommand;
use App\Domain\ValueObjects\Guid;
use Psr\Log\LoggerInterface;

/**
 * Creates complete Linnworks stock items with atomic transaction semantics.
 *
 * Handles the full item creation flow:
 * 1. [LOCKED] Generate SKU + create inventory item
 * 2. Link default supplier
 * 3. Add extended properties
 * 4. Add image (if provided)
 *
 * On failure after item creation, automatically rolls back by deleting the item.
 * This service is reusable for any Linnworks item creation - not tied to variations.
 *
 * @template-pattern Application Service
 */
final readonly class LinnworksStockItemCreatorService
{
    private const int LOCK_TIMEOUT_SECONDS = 30;

    public function __construct(
        private InventoryClientInterface $inventoryClient,
        private InventoryUpdateClientInterface $inventoryUpdateClient,
        private LockManagerInterface $lockManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * Create a complete Linnworks stock item.
     *
     * Atomic operation: generates SKU under distributed lock, creates item,
     * links supplier, adds extended properties, and optionally adds image.
     * Automatically rolls back (deletes item) on any failure after creation.
     *
     * Returns both SKU and stockItemId so callers can perform rollback if
     * subsequent operations fail (e.g., updating external systems).
     *
     * @return array{Sku, Guid} Tuple of [generated SKU, stockItemId]
     *
     * @throws LockAcquisitionException When SKU generation lock unavailable
     * @throws ResourceNotFoundException When category or supplier not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function create(CreateStockItemParams $params, bool $skipSupplier = false): array
    {
        $this->logger->debug('Creating Linnworks stock item', [
            'category_id' => $params->categoryId->value,
            'title' => $params->title,
        ]);

        $stockItemId = null;

        try {
            // LOCKED: Generate SKU and create item atomically
            [$sku, $stockItemId] = $this->lockManager->withLock(
                LockName::SkuGeneration->value,
                self::LOCK_TIMEOUT_SECONDS,
                function () use ($params): array {
                    $sku = $this->inventoryClient->getNewItemNumber();

                    $stockItemId = $this->inventoryUpdateClient->addInventoryItem(
                        $params->categoryId,
                        LinnworksStockItemCreatorService::buildAddItemCommand($sku, $params),
                    );

                    return [$sku, $stockItemId];
                },
            );

            // Outside lock: Complete item setup
            $this->completeItemSetup($stockItemId, $params, $skipSupplier);

            $this->logger->info('Linnworks stock item created', [
                'sku' => $sku->value,
                'stock_item_id' => $stockItemId->value,
            ]);

            return [$sku, $stockItemId];
        } catch (ResourceNotFoundException|InvalidApiRequestException|InvalidApiResponseException|AuthenticationExpiredException|ExternalServiceUnavailableException $e) {
            // Attempt rollback if item was created
            if ($stockItemId !== null) {
                $this->attemptRollback($stockItemId, $params->title);
            }

            throw $e;
        }
    }

    /**
     * Build the AddInventoryItemCommand from the params.
     */
    private static function buildAddItemCommand(Sku $sku, CreateStockItemParams $params): AddInventoryItemCommand
    {
        return new AddInventoryItemCommand(
            sku: $sku,
            title: $params->title,
            retailPrice: $params->retailPrice,
            purchasePrice: $params->purchasePrice,
            taxRate: $params->taxRate,
            barcode: $params->barcode,
            mpn: $params->mpn,
        );
    }

    /**
     * Complete item setup: supplier, extended properties, image.
     *
     * @throws ResourceNotFoundException When stock item or supplier not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    private function completeItemSetup(Guid $stockItemId, CreateStockItemParams $params, bool $skipSupplier): void
    {
        // Link supplier (skipped when --no-supplier flag is used)
        if (! $skipSupplier) {
            $this->inventoryUpdateClient->createSupplierStat(
                identifier: $stockItemId,
                supplierId: $params->supplierId,
                purchasePrice: $params->purchasePrice,
                supplierCode: $params->supplierCode,
                isDefault: true,
            );
        }

        // Add extended properties
        foreach ($params->extendedProperties as $name => $value) {
            $this->inventoryUpdateClient->addExtendedProperty(
                identifier: $stockItemId,
                name: $name,
                value: $value,
            );
        }

        // Add image if provided
        if ($params->imageUrl !== null) {
            $this->inventoryUpdateClient->addImage($stockItemId, $params->imageUrl);
        }
    }

    /**
     * Attempt to delete Linnworks item on failure.
     */
    private function attemptRollback(Guid $stockItemId, string $title): void
    {
        try {
            $this->inventoryUpdateClient->deleteInventoryItem($stockItemId);
            $this->logger->info('Rolled back Linnworks item', [
                'stock_item_id' => $stockItemId->value,
                'title' => $title,
            ]);
        } catch (ResourceNotFoundException|InvalidApiRequestException|InvalidApiResponseException|AuthenticationExpiredException|ExternalServiceUnavailableException $e) {
            // Critical: orphaned item in Linnworks
            $this->logger->critical('Failed to rollback Linnworks item - manual cleanup required', [
                'stock_item_id' => $stockItemId->value,
                'title' => $title,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
