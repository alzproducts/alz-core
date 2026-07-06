<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Catalog\Queries\ProductDetailQueryParams;
use App\Application\Catalog\Queries\ProductListQueryParams;
use App\Application\Contracts\RepositoryWriteInterface;
use App\Application\Shopwired\Enums\ExternalIdScope;
use App\Application\Shopwired\Enums\SkuListShape;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Enums\ProductType;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Domain\ValueObjects\PaginatedList;
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
     * Paginate products with optional eager-loaded relations and filters.
     *
     * @return PaginatedList<ProductView>
     *
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function paginate(ProductListQueryParams $query): PaginatedList;

    /**
     * Find a product view by external ID with conditional includes.
     *
     * Returns the read-only API projection with requested embeds loaded.
     * Unloaded relations/enrichments are null on the ProductView VO.
     *
     * @throws RecordNotFoundException When no product matches the ID
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findProductView(ProductDetailQueryParams $query): ProductView;

    /**
     * Get all external IDs for products or variations.
     *
     * @return list<int> ShopWired external IDs
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getAllExternalIds(ExternalIdScope $scope): array;

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
     * Behaviour depends on `$type`:
     * - `ProductType::Main` (default): single-table product lookup
     * - `ProductType::Variation`: single-table variation lookup
     * - `null`: searches products then variations (fallback)
     *
     * @return ($type is null ? Product|ProductVariation : ($type is ProductType::Main ? Product : ProductVariation))
     *
     * @throws RecordNotFoundException When no product or variation matches
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws MissingRequiredDataException When custom field definitions table is empty
     */
    public function getProduct(Sku|IntId $identifier, ?ProductType $type = ProductType::Main): Product|ProductVariation;

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
     * Get all SKUs from products and variations.
     *
     * @return ($shape is SkuListShape::GroupedByProduct ? array<int, list<string>> : list<string>)
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getAllSkus(SkuListShape $shape = SkuListShape::Flat): array;

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
     * @throws RecordNotFoundException When no product/variation found with this SKU
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
     * @throws RecordNotFoundException When no product or variation has this SKU
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
     * @throws RecordNotFoundException When no product found with this external ID
     * @throws DatabaseOperationFailedException On deletion failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function deleteByExternalId(IntId $externalId): void;

    /**
     * Get all on-sale products as read-side ProductView projections.
     *
     * Eager-loads variations and typed custom fields so the View-based
     * automatic sale removal flow can evaluate expiration without touching
     * the write-side Product VO.
     *
     * @return list<ProductView>
     *
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function findProductViewsOnSale(): array;

    /**
     * Get the external IDs of all products currently tagged with a given category.
     *
     * Uses the GIN-indexed jsonb containment predicate on
     * `shopwired.products.category_ids`.
     *
     * @return list<int> ShopWired product external IDs
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findExternalIdsInCategory(int $categoryId): array;

    /**
     * Check whether a single product has sale state drift.
     *
     * Detects two types of drift:
     * - On sale (sale_price > 0, < price) but NOT in sale category or missing sale custom fields
     * - NOT on sale but still in sale category or has sale custom fields
     *
     * @param IntId $productId Product external ID to check
     * @param int $saleCategoryId The ShopWired sale category ID
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function hasSaleStateDrift(IntId $productId, int $saleCategoryId): bool;

    /**
     * Find all products with sale state drift.
     *
     * Scans the entire catalog for products where DB sale state is
     * inconsistent with expected state. Used by the bulk reconciliation job.
     *
     * @param int $saleCategoryId The ShopWired sale category ID
     *
     * @return list<int> External IDs of products with sale state drift
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getAllProductsWithSaleStateDrift(int $saleCategoryId): array;
}
