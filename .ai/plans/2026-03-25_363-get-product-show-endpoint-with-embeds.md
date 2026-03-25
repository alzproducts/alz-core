# Plan: GET /api/products/{productId} — Show Product with Embeds

## Context

The consumer API currently has `GET /api/products` (paginated list) but no way to fetch a single product with detailed data. Consumers need a show endpoint that returns a product by ID with optional embeds for heavier/cross-domain data (description, cost prices from Linnworks, etc.).

**Route**: `GET /api/products/{productId}` (ShopWired external int ID)
**Embeds** via `?include=`: `variations`, `description`, `cost_price`, `category_ids`, `custom_fields`, `filters`
**Cost price source**: Linnworks `stock_item_suppliers.purchase_price` (default supplier), NOT ShopWired
**Response shape**: cost_price appears as a field on each product/variation object

### Key design decisions
- **Cost price factory in Infrastructure** (injected into mappers, like `ProductCustomFieldFactory`) — avoids Application→Infrastructure Deptrac violation
- **New `ProductVariationModelMapper`** with `toReadDomain()` — dedicated read-path mapper for variations that enriches with Linnworks cost prices. Existing `ProductVariationModel::toDomain()` stays untouched for write/internal path
- **Write/Read model split direction** — this PR introduces `toReadDomain()` on the variation mapper as a step toward a full Write Model / Read Model split (future work, not in scope here)
- **Includes control data loading, not just serialization** — consistent with the list endpoint where `paginate($perPage, $page, $includes)` conditionally eager-loads. The show endpoint passes `includes` to the repository, which passes them to the mapper for conditional enrichment

---

## Commit 1: Namespace Refactor — Move Product Models & Mapper

Move from ShopWired-specific to cross-integration namespace since the mapper will now draw from Linnworks too.

### Moves (use `git mv`)
| From | To |
|---|---|
| `app/Infrastructure/Shopwired/Models/ProductModel.php` | `app/Infrastructure/Catalog/Product/Models/ProductModel.php` |
| `app/Infrastructure/Shopwired/Models/ProductVariationModel.php` | `app/Infrastructure/Catalog/Product/Models/ProductVariationModel.php` |
| `app/Infrastructure/Shopwired/Mappers/ProductModelMapper.php` | `app/Infrastructure/Catalog/Product/Mappers/ProductModelMapper.php` |

### Update imports in all consumers
Grep for old namespace, update all. Key files:
- `EloquentProductRepository.php`
- `ProductCustomFieldFactory.php`
- `ProductFilterFactory.php`
- `ProductDomainFactory.php`
- `ProductLookupTableProvider.php`
- Test files referencing these models/mappers

### Deptrac / PHPArkitect
- Both old and new paths are under `App\Infrastructure\` — no deptrac.yaml changes needed
- Verify `make lint` passes after the move

---

## Commit 2: StockItem Cost Price Repository Method

### `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php` — Add method
```php
/**
 * Get all default supplier cost prices keyed by SKU.
 *
 * @return array<string, float> SKU → purchase_price from default supplier
 *
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 */
public function getCostPricesBySku(): array;
```

### `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php` — Implement
Query (proven join from `ProductLookupTableProvider`):
```sql
SELECT si.item_number AS sku, s.purchase_price
FROM linnworks.stock_items si
JOIN linnworks.stock_item_suppliers s
    ON s.stock_item_id = si.stock_item_id AND s.is_default = true
WHERE si.item_number IS NOT NULL
    AND si.item_number != ''
    AND s.purchase_price IS NOT NULL
```
- Wrap in `$this->eloquentGateway->query()` for exception translation
- Return `array<string, float>` keyed by SKU

---

## Commit 3: ProductCostPriceFactory + ProductVariationModelMapper

### `app/Infrastructure/Catalog/Product/Factories/ProductCostPriceFactory.php` — New
Follows `ProductCustomFieldFactory` pattern — lazy-loads ALL cost prices on first access, then O(1) lookups:
```php
final class ProductCostPriceFactory
{
    /** @var array<string, float>|null */
    private ?array $costPrices = null;

    public function __construct(
        private readonly StockItemRepositoryInterface $stockItemRepository,
    ) {}

    /** O(1) lookup by SKU. Lazy-loads all cost prices on first call. */
    public function getCostPrice(string $sku): ?float
    {
        return $this->costPrices()[$sku] ?? null;
    }

    /**
     * Get all cost prices, lazy-loading on first access.
     * Single query loads entire SKU → cost price map.
     *
     * @return array<string, float>
     */
    private function costPrices(): array
    {
        if ($this->costPrices === null) {
            $this->costPrices = $this->stockItemRepository->getCostPricesBySku();
        }
        return $this->costPrices;
    }
}
```

Register as `scoped()` binding in `AppServiceProvider` (Octane safety — fresh per request/job).

### `app/Infrastructure/Catalog/Product/Mappers/ProductVariationModelMapper.php` — New
Dedicated read-path mapper for variations. `ProductVariationModel::toDomain()` stays untouched for write/internal path.

```php
final class ProductVariationModelMapper
{
    public function __construct(
        private readonly ProductCostPriceFactory $costPriceFactory,
    ) {}

    /**
     * Read-path mapping: enriches with Linnworks cost price (lazy-loaded).
     * Mirrors ProductModelMapper::toReadDomain() vs toDomain() split.
     */
    public function toReadDomain(ProductVariationModel $model): ProductVariation
    {
        $costPrice = ($model->sku !== null)
            ? $this->costPriceFactory->getCostPrice($model->sku) ?? $model->cost_price
            : $model->cost_price;

        return new ProductVariation(
            id: $model->external_id,
            productExternalId: $model->product_external_id,
            sku: $model->sku,
            price: $model->price,
            costPrice: $costPrice,
            salePrice: $model->sale_price,
            stock: $model->stock,
            weight: $model->weight,
            gtin: $model->gtin !== null ? Gtin::fromTrusted($model->gtin) : null,
            mpn: $model->mpn,
            imageIndex: $model->image_index,
            options: self::buildOptions($model->options),
        );
    }

    /** Extracted from ProductVariationModel — make that method public static, or duplicate the simple array_map */
    private static function buildOptions(array $options): array { /* ... */ }
}
```

### Update `ProductModelMapper` — Inject new dependencies
```php
public function __construct(
    private readonly ProductCustomFieldFactory $customFieldFactory,
    private readonly ProductFilterFactory $filterFactory,
    private readonly ProductCostPriceFactory $costPriceFactory,         // NEW
    private readonly ProductVariationModelMapper $variationMapper,      // NEW
) {}
```

Three mapping paths on `ProductModelMapper`:

| Method | Used by | Variations | Cost price | Custom fields | Filters |
|---|---|---|---|---|---|
| `toDomain()` | `getProduct()` (internal) | Always | ShopWired | Always | Always |
| `toReadDomain()` (static) | `paginate()` (list API) | Conditional | ShopWired | Never | Never |
| `toApiDomain()` **NEW** | `findProductForApi()` (show API) | Conditional | Conditional (Linnworks) | Conditional | Conditional |

- `toDomain()` and `toReadDomain()` — **no changes**
- `toApiDomain()` — new method, uses `includes` array to conditionally enrich (see Commit 4)
- `ProductVariationModel::toDomain()` stays untouched for write/internal callers

---

## Commit 4: Repository Method + GetProductUseCase + Result DTO

### `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` — Add method
New method that accepts includes (existing `getProduct()` stays untouched for internal callers):
```php
/**
 * Find a product by external ID with conditional includes.
 *
 * Follows the same pattern as paginate() — includes control what's loaded.
 * Unloaded relations/enrichments are null on the Product VO.
 *
 * @param list<string> $includes Embed names to load (variations, cost_price, etc.)
 */
public function findProductForApi(IntId $productId, array $includes = []): Product;
```

### `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` — Implement
```php
public function findProductForApi(IntId $productId, array $includes = []): Product
{
    $relations = in_array('variations', $includes, true) ? ['variations'] : [];

    return $this->eloquentGateway->findOrFail(
        modelClass: self::MODEL_CLASS,
        column: 'external_id',
        value: $productId->value,
        relations: $relations,
        entityTypeName: 'Product',
        mapper: fn(ProductModel $model): Product => $this->mapper->toApiDomain($model, $includes),
    );
}
```

### `ProductModelMapper` — Add `toApiDomain()` method
New method alongside existing `toDomain()` and `toReadDomain()`:
```php
/**
 * Map for API show endpoint — conditionally enriches based on includes.
 *
 * @param list<string> $includes Embed names requested
 */
public function toApiDomain(ProductModel $model, array $includes): Product
{
    // Variations: conditional (like paginate)
    $variations = ($model->relationLoaded('variations') && in_array('variations', $includes, true))
        ? $model->variations->map(
            fn(ProductVariationModel $m) => $this->variationMapper->toReadDomain($m),
        )->all()
        : null;

    // Cost price: conditional
    $costPrice = (in_array('cost_price', $includes, true) && $model->sku !== null)
        ? $this->costPriceFactory->getCostPrice($model->sku) ?? $model->cost_price
        : $model->cost_price;

    // Custom fields: conditional
    $customFields = in_array('custom_fields', $includes, true)
        ? $this->customFieldFactory->fromRawFields($model->custom_fields)
        : [];

    // Filters: conditional
    $filters = in_array('filters', $includes, true)
        ? $this->filterFactory->fromRawFilters($model->filters ?? [])
        : [];

    return new Product(
        // ... same field mapping as toDomain() but using conditional values above
        costPrice: $costPrice,
        variations: $variations,
        customFields: $customFields,
        filters: $filters,
        // description, categoryIds always loaded (cheap, on the model row)
        // ...
    );
}
```

### `app/Application/Catalog/UseCases/GetProductResult.php` — New
```php
final readonly class GetProductResult
{
    /** @param list<string> $includes */
    public function __construct(
        public Product $product,
        public array $includes,
    ) {}

    public function hasInclude(string $name): bool
    {
        return in_array($name, $this->includes, true);
    }
}
```

### `app/Application/Catalog/UseCases/GetProductUseCase.php` — New
```php
final readonly class GetProductUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private LoggerInterface $logger,
    ) {}

    /** @param list<string> $includes */
    public function execute(int $productId, array $includes = []): GetProductResult
    {
        $this->logger->info('Getting product', ['product_id' => $productId, 'includes' => $includes]);

        $product = $this->productRepository->findProductForApi(
            IntId::from($productId),
            $includes,
        );

        return new GetProductResult(
            product: $product,
            includes: $includes,
        );
    }
}
```

Use case passes `includes` to the repository (same pattern as `ListProductsUseCase` → `paginate()`). The mapper conditionally enriches. The result carries the includes list so the resource knows what was loaded.

**@throws**: `ResourceNotFoundException`, `InvalidCustomFieldValueException`, `DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException`

---

## Commit 5: Presentation Layer (DTO, Resource, Controller, Route)

### `app/Presentation/Http/Api/DTOs/ShowProductRequestDTO.php` — New
Follow `ListProductsRequestDTO` pattern (copy and modify):
```php
final class ShowProductRequestDTO extends Data
{
    public function __construct(
        #[Nullable, StringType]
        public readonly ?string $include = null,
    ) {}

    // Same rules() closure validation against allowedIncludes()
    // Same validatedIncludes() method

    public static function allowedIncludes(): array
    {
        return ['variations', 'description', 'cost_price', 'category_ids', 'custom_fields', 'filters'];
    }
}
```

### `app/Presentation/Http/Api/Resources/ProductDetailResource.php` — New
Wraps `GetProductResult`:

**Base fields (always)**: same 20 fields as `ProductResource` — id, sku, gtin, title, slug, url, price, sale_price, compare_price, stock, is_active, vat_exclusive, vat_relief, weight, meta_title, meta_description, sort_order, images, created_at, updated_at

**Conditional on includes**:
- `variations` → reuse `ProductVariationResource::collection()` (variation VOs already have Linnworks cost_price from mapper)
- `description` → `string|null` (raw HTML)
- `cost_price` → `float|null` (product's costPrice — already Linnworks-enriched by mapper)
- `category_ids` → `list<int>`
- `custom_fields` → array of `{name, type, value}` objects (serialize `AbstractCustomFieldValue`)
- `filters` → array of `{title, values}` objects (serialize `ProductFilter`)

**Variation cost_price**: When `cost_price` is included, the mapper enriches `ProductVariation.costPrice` with Linnworks data via `ProductVariationModelMapper::toReadDomain()`. Add `cost_price` to `ProductVariationResource` — it serializes whatever value is on the VO (Linnworks when enriched, ShopWired otherwise).

### `app/Presentation/Http/Api/Controllers/ProductController.php` — Modify
- Add `GetProductUseCase` to constructor
- Add `show(int $productId, ShowProductRequestDTO $data): ProductDetailResource`

### `routes/api.php` — Add route
```php
Route::get('products/{productId}', [ProductController::class, 'show'])
    ->whereNumber('productId');
```
Inside the existing consumer API middleware group (JWT + approval + throttle + Sentry).

### Exception handling
- `ResourceNotFoundException` → 404 (already mapped in `InternalApiExceptionMapper`)
- Route constraint `->whereNumber()` rejects non-numeric productId with 404

---

## Commit 6: Tests

### `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php` — Add tests

**Auth**: reuse existing patterns (unauthenticated → 401, unapproved → 403)

**Happy path tests**:
- Base response (no includes) — verify 20 fields present, no embeds
- `?include=variations` — verify variations array present with cost_price on each
- `?include=description` — verify description field present
- `?include=cost_price` — verify cost_price field on product
- `?include=cost_price,variations` — verify cost_price on product AND each variation
- `?include=category_ids` — verify category_ids array
- `?include=custom_fields` — verify serialized custom field objects
- `?include=filters` — verify serialized filter objects
- Multiple includes combined

**Error tests**:
- Nonexistent product → 404
- Invalid include → 422
- Non-numeric productId → 404 (route constraint)

**Test setup**: Mock `ProductRepositoryInterface` and `StockItemRepositoryInterface`. Use existing test helpers.

---

## Key Patterns to Reuse

| What | Where |
|---|---|
| `ListProductsRequestDTO` | Pattern for `ShowProductRequestDTO` (`app/Presentation/Http/Api/DTOs/`) |
| `ProductResource` | Base field list for `ProductDetailResource` (`app/Presentation/Http/Api/Resources/`) |
| `ProductVariationResource` | Reuse for variations embed (`app/Presentation/Http/Api/Resources/`) |
| `ProductCustomFieldFactory` | Pattern for `ProductCostPriceFactory` (`app/Infrastructure/Shopwired/Factories/`) |
| `ProductLookupTableProvider` SQL join | Proven cost price query (`app/Infrastructure/Mixpanel/LookupTables/`) |
| `InternalApiExceptionMapper` | Already maps `ResourceNotFoundException` → 404 |
| `paginate()` with `$includes` | Pattern for `findProductForApi()` — conditional loading (`app/Infrastructure/Shopwired/Repositories/`) |
| `ProductControllerTest` | Test patterns to follow (`tests/Feature/Presentation/Http/Api/Controllers/`) |

---

## Verification

1. `make lint` — passes after each commit (Pint, PHPStan, PHPArkitect, Deptrac)
2. `make test` — all existing + new tests pass
3. Manual test via local bypass:
   ```
   GET /api/products/12345
   GET /api/products/12345?include=variations,description,cost_price
   ```
   With header: `X-Local-Bypass: <secret>`
4. Verify 404 for nonexistent product ID
5. Verify 422 for invalid include value
6. Verify cost_price on product and variations comes from Linnworks (not ShopWired DB value)
