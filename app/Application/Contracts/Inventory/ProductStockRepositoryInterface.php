<?php

declare(strict_types=1);

namespace App\Application\Contracts\Inventory;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Inventory\ValueObjects\ItemStockLevel;

/**
 * Local ShopWired stock level repository.
 *
 * Reads and updates the stock columns on the local shopwired.products and
 * shopwired.product_variations tables. Used by stock sync use cases to
 * avoid redundant API pushes when stock hasn't changed.
 */
interface ProductStockRepositoryInterface
{
    /**
     * Get stock levels for all SKU-bearing products and variations.
     *
     * Stock values are floored at zero — ShopWired permits negative stock
     * (overselling), but callers receive non-negative quantities only.
     *
     * @return list<ItemStockLevel>
     *
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function getAllStockLevels(): array;

    /**
     * Get stock levels for a specific set of SKUs.
     *
     * SKUs not found locally are omitted from the result. Stock values
     * are floored at zero — ShopWired permits negative stock (overselling),
     * but callers receive non-negative quantities only.
     *
     * @param list<Sku> $skus
     *
     * @return list<ItemStockLevel>
     *
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function getStockLevelsBySkus(array $skus): array;

    /**
     * Bulk-update stock for the given items.
     *
     * Resolves product vs. variation routing internally. SKUs not found
     * locally are silently skipped.
     *
     * @param list<ItemStockLevel> $items
     *
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function updateStockLevels(array $items): void;
}
