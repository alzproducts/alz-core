<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\Contracts\BasicProductInterface;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;

/**
 * Repository for ShopWired product persistence.
 *
 * Extends base ShopWired repository with product-specific methods.
 * Products include variations, which are managed via cascade operations.
 *
 * @extends ShopwiredRepositoryInterface<Product>
 */
interface ProductRepositoryInterface extends ShopwiredRepositoryInterface
{
    /**
     * Get all product external IDs stored locally.
     *
     * Returns ShopWired product IDs for all products in the database.
     * Use for reconciliation to compare against API product IDs.
     *
     * @return list<int> ShopWired product IDs
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getAllExternalIds(): array;

    /**
     * Delete products by their ShopWired external IDs.
     *
     * Removes orphaned products that no longer exist in ShopWired.
     * Variations are cascade-deleted via foreign key constraint.
     *
     * @param list<int> $externalIds ShopWired product IDs to delete
     *
     * @return int Number of products deleted
     *
     * @throws DatabaseOperationFailedException On deletion failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function deleteByExternalIds(array $externalIds): int;

    /**
     * Get a product or variation by SKU.
     *
     * Searches both products (master SKU) and variations (variant SKU).
     * Returns whichever matches first via the common BasicProductInterface.
     *
     * Use this when you need basic product data (pricing, stock, identification)
     * without caring whether it's a parent product or a specific variation.
     *
     * @throws ResourceNotFoundException When no product or variation matches the SKU
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getBasicProductBySku(string $sku): BasicProductInterface;
}
