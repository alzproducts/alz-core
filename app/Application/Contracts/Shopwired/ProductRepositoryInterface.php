<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\ValueObjects\IntId;
use Generator;

/**
 * Repository for ShopWired product persistence.
 *
 * Products include variations, which are managed via cascade operations.
 *
 * @extends RepositoryWriteInterface<Product>
 */
interface ProductRepositoryInterface extends RepositoryWriteInterface
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
     * Get all variation external IDs stored locally.
     *
     * Returns ShopWired variation IDs for all product variations in the database.
     * Use for reconciliation to compare against API variation IDs.
     *
     * @return list<int> ShopWired variation IDs
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getAllVariationExternalIds(): array;

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
     * Get a product or variation by identifier.
     *
     * Accepts either SKU or IntId:
     * - SKU: Searches products (master SKU) then variations (variant SKU)
     * - IntId: Looks up variation directly by external ID
     *
     * Use this when you need to look up without knowing whether
     * it's a parent product or a specific variation.
     *
     * @param Sku|IntId $identifier SKU to search, or variation external ID
     *
     * @throws ResourceNotFoundException When no product or variation matches
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getBasicProduct(Sku|IntId $identifier): Product|ProductVariation;

    /**
     * Get a full product by SKU with typed custom fields.
     *
     * Returns the complete Product value object including variations, images,
     * and typed custom field values.
     *
     * IMPORTANT: This method only searches master product SKUs, NOT variation SKUs.
     * If the given SKU belongs to a variation, this will throw ResourceNotFoundException
     * even though the SKU exists in the system. Callers must know the SKU type beforehand
     * or use getBasicProduct() which searches both.
     *
     * TODO: This interface needs expansion to support SKU type disambiguation. When
     * integrating with systems like Linnworks that send SKUs without type context,
     * callers need a way to determine whether a SKU is a product or variation before
     * calling type-specific methods. Consider adding findBySku(): Product|ProductVariation
     * or getSkuType(): SkuType when concrete use cases emerge.
     *
     * @throws ResourceNotFoundException When no product matches the SKU (includes variation SKUs)
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getProductBySku(string $sku): Product;

    /**
     * Stream all products with full data (memory-efficient).
     *
     * Yields Product objects one at a time using a generator pattern.
     * Each product includes variations, images, and typed custom fields.
     *
     * IMPORTANT: Exceptions throw during iteration, not at method call.
     * Wrap the foreach loop in try/catch, not the streamAll() call.
     *
     * @return Generator<int, Product> Yields products (array index as key)
     *
     * @throws InvalidCustomFieldValueException During iteration - value type mismatch
     * @throws DatabaseOperationFailedException During iteration - query failure
     * @throws ExternalServiceUnavailableException During iteration - DB unavailable
     */
    public function streamAll(): Generator;
}
