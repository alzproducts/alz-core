<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
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
 * @extends AbstractEloquentRepository<Product>
 */
final class EloquentProductRepository extends AbstractEloquentRepository implements ProductRepositoryInterface
{
    /** @var class-string<ProductModel> */
    private const string MODEL_CLASS = ProductModel::class;

    /** @var list<string> */
    private const array EAGER_LOAD_RELATIONS = ['variations'];

    public function __construct(
        DatabaseGatewayInterface $gateway,
        EloquentGateway $eloquentGateway,
        private readonly ProductModelMapper $mapper,
    ) {
        parent::__construct($gateway, $eloquentGateway);
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
        return $this->eloquentGateway->query(static function (): array {
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
        // Variations are cascade-deleted via FK constraint
        return $this->eloquentGateway->deleteWhereIn(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            values: $externalIds,
        );
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
    public function getBasicProductBySku(string $sku): Product|ProductVariation
    {
        // Try product master SKU first
        try {
            return $this->eloquentGateway->findOrFail(
                modelClass: self::MODEL_CLASS,
                column: 'sku',
                value: $sku,
                relations: self::EAGER_LOAD_RELATIONS,
                entityTypeName: 'Product',
                mapper: fn(ProductModel $model): Product => $this->mapModelToDomain($model),
            );
        } catch (ResourceNotFoundException) {
            Log::debug('Product not found by SKU, trying variation', ['sku' => $sku]);
        }

        // Try variation SKU - throws if neither found
        return $this->eloquentGateway->findOrFail(
            modelClass: ProductVariationModel::class,
            column: 'sku',
            value: $sku,
            relations: [],
            entityTypeName: 'Product or Variation',
            mapper: static fn(ProductVariationModel $model): ProductVariation => $model->toDomain(),
        );
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
        return $this->eloquentGateway->findOrFail(
            modelClass: self::MODEL_CLASS,
            column: 'sku',
            value: $sku,
            relations: self::EAGER_LOAD_RELATIONS,
            entityTypeName: 'Product',
            mapper: fn(ProductModel $model): Product => $this->mapModelToDomain($model),
        );
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
        yield from $this->eloquentGateway->streamAll(
            modelClass: self::MODEL_CLASS,
            relations: self::EAGER_LOAD_RELATIONS,
            mapper: fn(ProductModel $model): Product => $this->mapModelToDomain($model),
        );
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
