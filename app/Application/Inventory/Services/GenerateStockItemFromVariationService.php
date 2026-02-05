<?php

declare(strict_types=1);

namespace App\Application\Inventory\Services;

use App\Application\Contracts\Linnworks\InventoryUpdateClientInterface;
use App\Application\Contracts\Shopwired\BasicProductUpdateClientInterface;
use App\Application\Inventory\Params\CreateStockItemParams;
use App\Domain\Catalog\Product\Commands\UpdateBasicProductCommand;
use App\Domain\Catalog\Product\Enums\ProductType;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\ValueObjects\Guid;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Generates Linnworks stock items from ShopWired variations with full transaction semantics.
 *
 * Coordinates between Linnworks and ShopWired:
 * 1. Create Linnworks stock item (via LinnworksStockItemCreatorService)
 * 2. Write generated SKU back to ShopWired variation
 * 3. On failure: rollback Linnworks item
 *
 * This service owns the cross-system transaction boundary.
 *
 * @template-pattern Application Service
 */
final readonly class GenerateStockItemFromVariationService
{
    public function __construct(
        private LinnworksStockItemCreatorService $stockItemCreator,
        private InventoryUpdateClientInterface $inventoryUpdateClient,
        private BasicProductUpdateClientInterface $shopwiredUpdateClient,
        private LoggerInterface $logger,
    ) {}

    /**
     * Generate a Linnworks stock item and write SKU back to ShopWired variation.
     *
     * @param CreateStockItemParams $params Stock item creation parameters
     * @param int $variationId ShopWired variation ID to update with generated SKU
     *
     * @return Sku The generated SKU
     *
     * @throws LockAcquisitionException When SKU generation lock unavailable
     * @throws ResourceNotFoundException When category, supplier, or variation not found
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function generate(CreateStockItemParams $params, int $variationId, bool $skipSupplier = false): Sku
    {
        // 1. Create Linnworks stock item
        [$sku, $stockItemId] = $this->stockItemCreator->create($params, $skipSupplier);

        // 2. Write SKU back to ShopWired variation
        try {
            $this->shopwiredUpdateClient->update(new UpdateBasicProductCommand(
                identifier: IntId::from($variationId),
                type: ProductType::Variation,
                newSku: $sku,
            ));

            return $sku;
        } catch (Throwable $e) {
            // 3. ShopWired failed - rollback Linnworks item
            $this->attemptRollback($stockItemId, $variationId);

            throw $e;
        }
    }

    /**
     * Attempt to delete Linnworks item after ShopWired update failure.
     */
    private function attemptRollback(Guid $stockItemId, int $variationId): void
    {
        try {
            $this->inventoryUpdateClient->deleteInventoryItem($stockItemId);
            $this->logger->info('Rolled back Linnworks item after ShopWired failure', [
                'stock_item_id' => $stockItemId->value,
                'variation_id' => $variationId,
            ]);
        } catch (Throwable $e) { // @ignoreException - rollback failure must not hide original error
            $this->logger->critical('Failed to rollback Linnworks item - manual cleanup required', [
                'stock_item_id' => $stockItemId->value,
                'variation_id' => $variationId,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
