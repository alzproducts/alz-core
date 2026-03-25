<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\DatabaseGatewayInterface;
use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Application\DTOs\PaginatedListDTO;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Catalog\Product\ValueObjects\ProductVariation;
use App\Domain\Catalog\Product\ValueObjects\ProductView;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use App\Infrastructure\Catalog\Product\Mappers\ProductModelMapper;
use App\Infrastructure\Catalog\Product\Mappers\ProductVariationModelMapper;
use App\Infrastructure\Catalog\Product\Models\ProductModel;
use App\Infrastructure\Catalog\Product\Models\ProductVariationModel;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use Generator;
use Illuminate\Database\Eloquent\Builder;
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
     * @return PaginatedListDTO<ProductView>
     *
     * @throws InvalidCustomFieldValueException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function paginate(int $perPage, int $page, array $includes = []): PaginatedListDTO
    {
        return $this->eloquentGateway->paginate(
            modelClass: self::MODEL_CLASS,
            scope: static function (Builder $q): void {
                $q->where('is_active', true)->orderBy('title');
            },
            relations: \in_array('variations', $includes, true) ? ['variations'] : [],
            mapper: fn(ProductModel $model): ProductView => $this->mapper->toViewDomain($model, $includes),
            perPage: $perPage,
            page: $page,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws InvalidCustomFieldValueException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function findProductForApi(IntId $productId, array $includes = []): ProductView
    {
        $relations = \in_array('variations', $includes, true) ? ['variations'] : [];

        return $this->eloquentGateway->findOrFail(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $productId->value,
            relations: $relations,
            entityTypeName: 'Product',
            mapper: fn(ProductModel $model): ProductView => $this->mapper->toViewDomain($model, $includes),
        );
    }

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
        /** @var Product $entity */
        $this->performSave($entity);
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function saveFromWebhook(Product $product, array $presentEmbeds = []): void
    {
        $this->performWebhookSave($product, $presentEmbeds);
    }

    /**
     * @param array<string, mixed> $extra Additional attributes merged into the product upsert.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function performSave(Product $product, array $extra = []): void
    {
        $this->performUpsert(
            product: $product,
            attributes: [...ProductModelMapper::toModelAttributes($product), ...$extra],
            shouldSyncVariations: $product->variations !== null,
        );
    }

    /**
     * Persist a product from webhook data, only including embed-dependent columns
     * that were actually present in the webhook payload.
     *
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     * @param array<string, mixed> $extra Additional attributes merged into the product upsert
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function performWebhookSave(Product $product, array $presentEmbeds, array $extra = []): void
    {
        $this->performUpsert(
            product: $product,
            attributes: [...ProductModelMapper::toWebhookAttributes($product, $presentEmbeds), ...$extra],
            shouldSyncVariations: \in_array('variations', $presentEmbeds, true) && $product->variations !== null,
        );
    }

    /**
     * Shared upsert + variation sync with error handling.
     *
     * @param array<string, mixed> $attributes Model attributes for the upsert
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function performUpsert(Product $product, array $attributes, bool $shouldSyncVariations): void
    {
        try {
            $this->eloquentGateway->transact(function () use ($product, $attributes, $shouldSyncVariations): void {
                $this->eloquentGateway->upsertOne(
                    modelClass: self::MODEL_CLASS,
                    attributes: $attributes,
                    uniqueBy: ['external_id'],
                );

                if ($shouldSyncVariations) {
                    $this->syncVariations($product);
                }
            }, attempts: 3);
        } catch (DatabaseOperationFailedException $e) {
            $this->logCrossTableSkuConflictIfApplicable($e, $product);

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
            /** @var list<int> */
            return self::MODEL_CLASS::query()
                ->pluck('external_id')
                ->all();
        });
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
    public function getAllVariationExternalIds(): array
    {
        return $this->eloquentGateway->query(static function (): array {
            /** @var list<int> */
            return ProductVariationModel::query()
                ->pluck('external_id')
                ->all();
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
     * Note: This method searches both tables sequentially when the entity type is unknown.
     * For better performance when you know the type, consider using specific repository methods.
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    public function getBasicProduct(Sku|IntId $identifier): Product|ProductVariation
    {
        return $identifier instanceof IntId
            ? $this->getBasicProductById($identifier)
            : $this->getBasicProductBySku($identifier);
    }

    /**
     * Look up product or variation by ShopWired external ID.
     *
     * Searches products first, then variations. Most external IDs will be
     * variations (SKU-less items), but products can also be looked up by ID.
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    private function getBasicProductById(IntId $id): Product|ProductVariation
    {
        // Try product first
        try {
            return $this->eloquentGateway->findOrFail(
                modelClass: self::MODEL_CLASS,
                column: 'external_id',
                value: $id->value,
                relations: self::EAGER_LOAD_RELATIONS,
                entityTypeName: 'Product',
                mapper: fn(ProductModel $model): Product => $this->mapModelToDomain($model),
            );
        } catch (ResourceNotFoundException) {
            Log::debug('Product not found by ID, trying variation', ['external_id' => $id->value]);
        }

        // Try variation - throws if neither found
        return $this->eloquentGateway->findOrFail(
            modelClass: ProductVariationModel::class,
            column: 'external_id',
            value: $id->value,
            relations: [],
            entityTypeName: 'Product or Variation',
            mapper: static fn(ProductVariationModel $model): ProductVariation => ProductVariationModelMapper::toDomain($model),
        );
    }

    /**
     * Look up product or variation by SKU.
     *
     * Searches products (master SKU) first, then variations (variant SKU).
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    private function getBasicProductBySku(Sku $sku): Product|ProductVariation
    {
        // Try product master SKU first
        try {
            return $this->eloquentGateway->findOrFail(
                modelClass: self::MODEL_CLASS,
                column: 'sku',
                value: $sku->value,
                relations: self::EAGER_LOAD_RELATIONS,
                entityTypeName: 'Product',
                mapper: fn(ProductModel $model): Product => $this->mapModelToDomain($model),
            );
        } catch (ResourceNotFoundException) {
            Log::debug('Product not found by SKU, trying variation', ['sku' => $sku->value]);
        }

        // Try variation SKU - throws if neither found
        return $this->eloquentGateway->findOrFail(
            modelClass: ProductVariationModel::class,
            column: 'sku',
            value: $sku->value,
            relations: [],
            entityTypeName: 'Product or Variation',
            mapper: static fn(ProductVariationModel $model): ProductVariation => ProductVariationModelMapper::toDomain($model),
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
    public function getProduct(Sku|IntId $identifier): Product
    {
        return $this->eloquentGateway->findOrFail(
            modelClass: self::MODEL_CLASS,
            column: self::columnForIdentifier($identifier),
            value: $identifier->value,
            relations: self::EAGER_LOAD_RELATIONS,
            entityTypeName: 'Product',
            mapper: fn(ProductModel $model): Product => $this->mapModelToDomain($model),
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getVariation(Sku|IntId $identifier): ProductVariation
    {
        return $this->eloquentGateway->findOrFail(
            modelClass: ProductVariationModel::class,
            column: self::columnForIdentifier($identifier),
            value: $identifier->value,
            relations: [],
            entityTypeName: 'Variation',
            mapper: static fn(ProductVariationModel $model): ProductVariation => ProductVariationModelMapper::toDomain($model),
        );
    }

    /**
     * Get the database column name for an identifier type.
     */
    private static function columnForIdentifier(Sku|IntId $identifier): string
    {
        return $identifier instanceof IntId ? 'external_id' : 'sku';
    }

    /**
     * {@inheritDoc}
     *
     * Uses lazy() with chunk size of 100 to balance memory efficiency with query overhead.
     *
     * @return Generator<int, Product>
     *
     * @throws DatabaseOperationFailedException During iteration - query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException During iteration - DB unavailable
     * @throws InvalidCustomFieldValueException During iteration - value type mismatch
     */
    public function streamAll(): Generator
    {
        yield from $this->eloquentGateway->streamAll(
            modelClass: self::MODEL_CLASS,
            relations: self::EAGER_LOAD_RELATIONS,
            mapper: fn(ProductModel $model): Product => $this->mapModelToDomain($model),
        );
    }

    /**
     * {@inheritDoc}
     *
     * Uses SQL UNION for single-pass query across both tables.
     *
     * @return list<string>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getAllSkus(): array
    {
        return $this->eloquentGateway->query(static function (): array {
            /** @var list<string> */
            return self::MODEL_CLASS::query()
                ->whereNotNull('sku')
                ->where('sku', '!=', '')
                ->select('sku')
                ->union(
                    ProductVariationModel::query()
                        ->whereNotNull('sku')
                        ->where('sku', '!=', '')
                        ->select('sku'),
                )
                ->pluck('sku')
                ->all();
        });
    }

    /**
     * {@inheritDoc}
     *
     * Single raw SQL query using UNION + json_agg for efficient grouping.
     *
     * @return array<int, list<string>>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getSkusGroupedByProductId(): array
    {
        return $this->eloquentGateway->query(static function (): array {
            $sql = <<<'SQL'
                SELECT product_id, json_agg(sku) as skus
                FROM (
                    SELECT external_id as product_id, sku
                    FROM shopwired.products
                    WHERE sku IS NOT NULL AND sku != ''
                    UNION ALL
                    SELECT product_external_id as product_id, sku
                    FROM shopwired.product_variations
                    WHERE sku IS NOT NULL AND sku != ''
                ) combined
                GROUP BY product_id
                SQL;

            /** @var list<object{product_id: int, skus: string}> $rows */
            $rows = self::MODEL_CLASS::query()->getConnection()->select($sql);

            /** @var array<int, list<string>> $result */
            $result = [];

            foreach ($rows as $row) {
                /** @var list<string> $skus */
                $skus = \json_decode($row->skus, true);
                $result[$row->product_id] = $skus;
            }

            return $result;
        });
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Abstract Method Implementations
    // ─────────────────────────────────────────────────────────────────────────

    protected function getModelClass(): string
    {
        return self::MODEL_CLASS;
    }

    protected function getEagerLoadRelations(): array
    {
        return self::EAGER_LOAD_RELATIONS;
    }

    protected function getEntityIdentifier(object $entity): int
    {
        /** @var Product $entity */
        return $entity->id;
    }

    /**
     * {@inheritDoc}
     *
     * @param Product $entity
     */
    protected function entityToAttributes(object $entity): array
    {
        /** @var Product $entity */
        return ProductModelMapper::toModelAttributes($entity);
    }

    /**
     * {@inheritDoc}
     */
    protected function getUpsertKeys(): array
    {
        return ['external_id'];
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException When custom field registry fails to load
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
     */
    protected function mapModelToDomain(Model $model): Product
    {
        /** @var ProductModel $model */
        return $this->mapper->toDomain($model);
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Webhook Partial Update Methods
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function updateStock(Sku $sku, bool $isVariation, int $newQuantity): void
    {
        $modelClass = $isVariation ? ProductVariationModel::class : self::MODEL_CLASS;

        $affected = $this->eloquentGateway->updateWhere(
            modelClass: $modelClass,
            column: 'sku',
            value: $sku->value,
            data: ['stock' => $newQuantity],
        );

        if ($affected === 0) {
            $entityType = $isVariation ? 'ProductVariation' : $this->getEntityTypeName();
            throw new ResourceNotFoundException('Database', $entityType, $sku->value);
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     */
    public function getProductByAnySku(Sku $sku): Product
    {
        // Try product master SKU first
        try {
            return $this->getProduct($sku);
        } catch (ResourceNotFoundException) {
            Log::debug('Product not found by master SKU, trying variations', ['sku' => $sku->value]);
        }

        // Find variation by SKU → get parent product's external ID
        /** @var int|null $productExternalId */
        $productExternalId = $this->eloquentGateway->query(
            static function () use ($sku): ?int {
                /** @var int|null */
                return ProductVariationModel::query()
                    ->where('sku', $sku->value)
                    ->value('product_external_id');
            },
        );

        if ($productExternalId === null) {
            throw new ResourceNotFoundException('Database', 'Product', $sku->value);
        }

        // Load parent product with variations
        return $this->getProduct(IntId::from($productExternalId));
    }

    /**
     * {@inheritDoc}
     *
     * @return list<Product>
     *
     * @throws InvalidCustomFieldValueException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getProductsOnSale(): array
    {
        return $this->eloquentGateway->query(function (): array {
            /** @var list<Product> */
            return self::MODEL_CLASS::query()
                ->with(self::EAGER_LOAD_RELATIONS)
                ->whereNotNull('sale_price')
                ->where('sale_price', '>', 0)
                ->get()
                ->map(fn(ProductModel $model): Product => $this->mapModelToDomain($model))
                ->all();
        });
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function hasSaleStateDrift(IntId $productId, int $saleCategoryId): bool
    {
        return $this->eloquentGateway->query(static fn(): bool => self::buildSaleStateDriftQuery($saleCategoryId)
                ->where('external_id', $productId->value)
                ->exists());
    }

    /**
     * {@inheritDoc}
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getAllProductsWithSaleStateDrift(int $saleCategoryId): array
    {
        return $this->eloquentGateway->query(static function () use ($saleCategoryId): array {
            /** @var list<int> */
            return self::buildSaleStateDriftQuery($saleCategoryId)
                ->pluck('external_id')
                ->all();
        });
    }

    /**
     * Build the shared query for detecting sale state drift.
     *
     * @return Builder<ProductModel>
     */
    private static function buildSaleStateDriftQuery(int $saleCategoryId): Builder
    {
        $saleCategoryJson = '[' . $saleCategoryId . ']';

        return self::MODEL_CLASS::query()
            ->select('external_id')
            ->where(static function (Builder $q) use ($saleCategoryJson): void {
                // Case 1: On sale but NOT in sale category or missing sale custom fields
                $q->where(static function (Builder $onSale) use ($saleCategoryJson): void {
                    $onSale->whereNotNull('sale_price')
                        ->where('sale_price', '>', 0)
                        ->whereRaw('sale_price < price')
                        ->where(static function (Builder $missing) use ($saleCategoryJson): void {
                            $missing->whereRaw('NOT (category_ids @> ?::jsonb)', [$saleCategoryJson])
                                ->orWhereRaw("custom_fields->>'sale_reason' IS NULL")
                                ->orWhereRaw("custom_fields->>'sale_reason' = ''");
                        });
                })
                // Case 2: NOT on sale but still in sale category or has orphaned sale custom fields
                ->orWhere(static function (Builder $notOnSale) use ($saleCategoryJson): void {
                    $notOnSale->where(static function (Builder $notSale): void {
                        $notSale->whereNull('sale_price')
                            ->orWhere('sale_price', '<=', 0)
                            ->orWhereRaw('sale_price >= price');
                    })
                    ->where(static function (Builder $hasArtifacts) use ($saleCategoryJson): void {
                        $hasArtifacts->whereRaw('category_ids @> ?::jsonb', [$saleCategoryJson])
                            ->orWhereRaw("(custom_fields->>'sale_reason') IS NOT NULL AND (custom_fields->>'sale_reason') != ''")
                            ->orWhereRaw("(custom_fields->>'sale_date_start') IS NOT NULL AND (custom_fields->>'sale_date_start') != ''")
                            ->orWhereRaw("(custom_fields->>'sale_date_end') IS NOT NULL AND (custom_fields->>'sale_date_end') != ''")
                            ->orWhereRaw("(custom_fields->>'sale_comments') IS NOT NULL AND (custom_fields->>'sale_comments') != ''")
                            ->orWhereRaw("(custom_fields->>'sale_ends_stock') IS NOT NULL AND (custom_fields->>'sale_ends_stock') != ''");
                    });
                });
            });
    }

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function deleteByExternalId(IntId $externalId): void
    {
        $deleted = $this->eloquentGateway->deleteWhere(
            modelClass: self::MODEL_CLASS,
            column: 'external_id',
            value: $externalId->value,
        );

        if ($deleted === 0) {
            throw new ResourceNotFoundException('Database', $this->getEntityTypeName(), $externalId->value);
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Persistence Helpers
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Sync product variations using delete+insert strategy.
     *
     * Deletes all existing variations by product_external_id, then bulk inserts fresh.
     * This is simpler than upsert/diff since variations rarely change independently
     * and the composite unique (product_external_id, external_id) ensures idempotency.
     *
     * Performance: Bulk insert reduces N queries to 1 (significant for 100+ variations).
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function syncVariations(Product $product): void
    {
        // 1. Delete existing variations using stable external ID
        $this->eloquentGateway->deleteWhere(
            modelClass: ProductVariationModel::class,
            column: 'product_external_id',
            value: $product->id,
        );

        // 2. Bulk insert fresh variations (single query vs N queries)
        if ($product->variations !== null && $product->variations !== []) {
            // Fetch product UUID for FK (single column query after upsert)
            /** @var string $productUuid */
            $productUuid = self::MODEL_CLASS::query()
                ->where('external_id', $product->id)
                ->value('id');

            /** @var list<array<string, mixed>> $rows */
            $rows = \array_map(
                static fn(ProductVariation $v): array => [
                    'product_id' => $productUuid,
                    ...ProductVariationModelMapper::toModelAttributes($v),
                ],
                $product->variations,
            );

            $this->eloquentGateway->insertMany(
                modelClass: ProductVariationModel::class,
                rows: $rows,
            );
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
