<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\Contracts\BasicProductInterface;
use App\Domain\Catalog\Product\Exceptions\MissingVariationSkuException;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\DatabaseOperationFailedException;
use App\Domain\Exceptions\DuplicateRecordException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\ResourceNotFoundException;
use App\Infrastructure\Shopwired\Mappers\ProductModelMapper;
use App\Infrastructure\Shopwired\Models\ProductModel;
use App\Infrastructure\Shopwired\Models\ProductVariationModel;
use Generator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Log;

/**
 * Eloquent implementation of ShopWired product repository.
 *
 * Persists Domain Product entities to PostgreSQL using Eloquent models.
 * Uses upsert strategy based on ShopWired's external ID for idempotent sync.
 *
 * Variation Sync Strategy:
 * - Delete all variations by product_external_id, then insert fresh
 * - Simpler than diffing, and variations rarely change independently
 * - Composite unique (product_external_id, external_id) ensures idempotency
 *
 * @extends AbstractShopwiredEloquentRepository<Product>
 */
final class EloquentProductRepository extends AbstractShopwiredEloquentRepository implements ProductRepositoryInterface
{
    /** @var class-string<ProductModel> */
    private const string MODEL_CLASS = ProductModel::class;

    private const string ENTITY_TYPE = 'Product';

    /** @var list<string> */
    private const array EAGER_LOAD_RELATIONS = ['variations'];

    public function __construct(
        DatabaseGatewayInterface $gateway,
        private readonly ProductModelMapper $mapper,
    ) {
        parent::__construct($gateway);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Interface Implementation
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @param Product $entity
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function save(object $entity): void
    {
        try {
            $this->gateway->transact(function () use ($entity): void {
                $model = $this->upsertProduct($entity);
                $this->syncVariations($model, $entity);
            }, attempts: 3);
        } catch (DatabaseOperationFailedException $e) {
            $this->logCrossTableSkuConflictIfApplicable($e, $entity);

            throw $e;
        }
    }

    /**
     * {@inheritDoc}
     *
     * @return list<int>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getAllExternalIds(): array
    {
        return $this->gateway->query(static function (): array {
            /** @var list<int> $ids */
            $ids = self::MODEL_CLASS::query()
                ->pluck('external_id')
                ->all();

            return $ids;
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteByExternalIds(array $externalIds): int
    {
        if ($externalIds === []) {
            return 0;
        }

        return $this->gateway->transact(static function () use ($externalIds): int {
            // Variations are cascade-deleted via FK constraint
            /** @var int $count */
            $count = self::MODEL_CLASS::query()
                ->whereIn('external_id', $externalIds)
                ->delete();

            return $count;
        }, attempts: 3);
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    public function getBasicProductBySku(string $sku): BasicProductInterface
    {
        return $this->gateway->query(function () use ($sku): BasicProductInterface {
            // Try product master SKU first
            /** @var ProductModel|null $product */
            $product = self::MODEL_CLASS::query()
                ->where('sku', $sku)
                ->with(self::EAGER_LOAD_RELATIONS)
                ->first();

            if ($product !== null) {
                return $this->mapModelToDomain($product);
            }

            // Try variation SKU
            /** @var ProductVariationModel|null $variation */
            $variation = ProductVariationModel::query()
                ->where('sku', $sku)
                ->first();

            if ($variation !== null) {
                try {
                    return $variation->toDomain();
                } catch (MissingVariationSkuException $e) {
                    // Data integrity issue - should never happen if sync validation works.
                    // Log and treat as not found rather than propagating an "impossible" exception.
                    Log::error('Variation in database has null SKU - data integrity issue', [
                        'variation_id' => $e->variationId,
                        'product_external_id' => $e->productExternalId,
                        'searched_sku' => $sku,
                    ]);
                }
            }

            throw new ResourceNotFoundException('Database', 'Product or Variation', $sku);
        });
    }

    /**
     * {@inheritDoc}
     *
     * Only searches master product SKUs, not variation SKUs.
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    public function getProductBySku(string $sku): Product
    {
        return $this->gateway->query(function () use ($sku): Product {
            /** @var ProductModel|null $product */
            $product = self::MODEL_CLASS::query()
                ->where('sku', $sku)
                ->with(self::EAGER_LOAD_RELATIONS)
                ->first();

            if ($product === null) {
                throw new ResourceNotFoundException('Database', 'Product', $sku);
            }

            return $this->mapModelToDomain($product);
        });
    }

    /**
     * {@inheritDoc}
     *
     * Uses lazy() with chunk size of 100 to balance memory efficiency with query overhead.
     *
     * @return Generator<int, Product>
     *
     * @throws InvalidCustomFieldValueException During iteration - value type mismatch
     * @throws DatabaseOperationFailedException During iteration - query failure
     * @throws ExternalServiceUnavailableException During iteration - DB unavailable
     */
    public function streamAll(): Generator
    {
        // Use lazy() for memory-efficient chunked iteration
        $lazyCollection = self::MODEL_CLASS::query()
            ->with(self::EAGER_LOAD_RELATIONS)
            ->lazy(100);

        foreach ($lazyCollection as $model) {
            /** @var ProductModel $model */
            yield $this->mapModelToDomain($model);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Abstract Method Implementations
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     */
    protected function getModelClass(): string
    {
        return self::MODEL_CLASS;
    }

    /**
     * {@inheritDoc}
     */
    protected function getEagerLoadRelations(): array
    {
        return self::EAGER_LOAD_RELATIONS;
    }

    /**
     * {@inheritDoc}
     */
    protected function getEntityIdentifier(object $entity): int
    {
        /** @var Product $entity */
        return $entity->id;
    }

    /**
     * {@inheritDoc}
     */
    protected function getEntityTypeName(): string
    {
        return self::ENTITY_TYPE;
    }

    /**
     * {@inheritDoc}
     *
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    protected function mapModelToDomain(Model $model): Product
    {
        /** @var ProductModel $model */
        return $this->mapper->toDomain($model);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Persistence Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Upsert product record based on external_id.
     */
    private function upsertProduct(Product $product): ProductModel
    {
        $attributes = ProductModelMapper::toModelAttributes($product);

        /** @var ProductModel $model */
        $model = self::MODEL_CLASS::query()->updateOrCreate(
            ['external_id' => $product->id],
            $attributes,
        );

        return $model;
    }

    /**
     * Sync product variations using delete+insert strategy.
     *
     * Deletes all existing variations by product_external_id, then inserts fresh.
     * This is simpler than upsert/diff since variations rarely change independently
     * and the composite unique (product_external_id, external_id) ensures idempotency.
     */
    private function syncVariations(ProductModel $model, Product $product): void
    {
        // Delete existing variations using stable external ID
        ProductVariationModel::query()
            ->where('product_external_id', $product->id)
            ->delete();

        // Insert fresh variations
        foreach ($product->variations as $variation) {
            $attributes = ProductVariationModel::fromDomainAttributes($variation);
            $attributes['product_id'] = $model->id;

            ProductVariationModel::query()->create($attributes);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Error Handling Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Log detailed context if exception is a cross-table SKU conflict.
     *
     * Our PostgreSQL trigger raises "Cross-table SKU conflict: 'ABC' already exists in shopwired.X".
     * This provides actionable logging with the product context for debugging.
     */
    private function logCrossTableSkuConflictIfApplicable(
        DatabaseOperationFailedException $e,
        Product $product,
    ): void {
        $conflict = self::extractCrossTableSkuConflict($e->getMessage());

        if ($conflict === null) {
            return;
        }

        Log::error('Cross-table SKU conflict - fix in ShopWired admin', [
            'product_external_id' => $product->id,
            'product_title' => $product->title,
            'conflicting_sku' => $conflict['sku'],
            'conflict_table' => $conflict['table'],
            'action_required' => 'SKU exists in both products and variations tables - ensure unique SKUs',
        ]);
    }

    /**
     * Extract SKU and conflict table from cross-table trigger message.
     *
     * Message format: "Cross-table SKU conflict: 'ABC123' already exists in shopwired.products"
     *
     * @return array{sku: string, table: string}|null
     */
    private static function extractCrossTableSkuConflict(string $message): ?array
    {
        // Match: Cross-table SKU conflict: 'SKU_VALUE' already exists in shopwired.TABLE_NAME
        if (\preg_match(
            "/Cross-table SKU conflict: '([^']+)' already exists in (shopwired\\.\\w+)/",
            $message,
            $matches,
        ) === 1) {
            return [
                'sku' => $matches[1],
                'table' => $matches[2],
            ];
        }

        return null;
    }
}
