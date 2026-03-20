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
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
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
     * Get a product by identifier (SKU or external ID).
     *
     * Returns the complete Product value object including variations, images,
     * and typed custom field values.
     *
     * Use when the caller knows they want a Product (not a variation).
     *
     * @param Sku|IntId $identifier Product SKU or external ID
     *
     * @throws ResourceNotFoundException When no product matches the identifier
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getProduct(Sku|IntId $identifier): Product;

    /**
     * Get a variation by identifier (SKU or external ID).
     *
     * Returns the ProductVariation value object.
     *
     * Use when the caller knows they want a Variation (not a parent product).
     *
     * **Prefer SKU over IntId:** ShopWired variation external IDs regenerate when the
     * parent product is saved, making them unstable identifiers. Use SKU when available
     * for reliable lookups. Only use IntId for SKU-less variations that were just synced.
     *
     * @param Sku|IntId $identifier Variation SKU or external ID
     *
     * @throws ResourceNotFoundException When no variation matches the identifier
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getVariation(Sku|IntId $identifier): ProductVariation;

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

    /**
     * Get all unique SKUs from products and variations.
     *
     * Returns distinct SKUs from both master products and variations.
     * Used for bulk operations (Reviews.io sync, feed generation, etc.)
     *
     * @return list<string> Unique SKU values
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getAllSkus(): array;

    /**
     * Get all SKUs grouped by product external ID.
     *
     * Returns a map: product external ID => list of SKUs (master + variations).
     *
     * @return array<int, list<string>>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getSkusGroupedByProductId(): array;

    /**
     * Update stock quantity for a product or variation by SKU.
     *
     * Used by `product.stock_changed` webhook. Updates the stock column
     * on either the products or product_variations table.
     *
     * @param Sku $sku SKU of the product or variation to update
     * @param bool $isVariation Whether the SKU refers to a variation
     * @param int $newQuantity New stock quantity
     *
     * @throws ResourceNotFoundException When no product/variation found with this SKU
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function updateStock(Sku $sku, bool $isVariation, int $newQuantity): void;

    /**
     * Upsert a product from webhook data.
     *
     * Only persists embed-dependent columns (vat_relief, categories, images, etc.)
     * that were actually present in the webhook payload. Core scalar fields are
     * always persisted.
     *
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function saveFromWebhook(Product $product, array $presentEmbeds = []): void;

    /**
     * Get the parent product that owns a SKU (master or variation).
     *
     * Searches products (master SKU) first, then variations (variant SKU).
     * Always returns the full parent Product with variations loaded.
     *
     * @throws ResourceNotFoundException When no product or variation has this SKU
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getProductByAnySku(Sku $sku): Product;

    /**
     * Delete a product by its ShopWired external ID.
     *
     * Used by `product.deleted` webhook. Cascades to variations via FK constraint.
     *
     * @throws ResourceNotFoundException When no product found with this external ID
     * @throws DatabaseOperationFailedException On deletion failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function deleteByExternalId(IntId $externalId): void;
}
