# Plan: Catalog Views and Query Infrastructure

> **Follows #429** which delivered: `ProductInclude` domain enum, `ProductListQueryParams` / `ProductDetailQueryParams` query objects, `ProductIncludeEnum` deletion, and all controller/use case/repo/assembler/resource updates to use domain-typed includes.

## Context

Product GET endpoints still have hardcoded filtering (`is_active = true`) and sorting (`orderBy('title')`) in the repository scope closure. Computed values like profit margin are assembled in PHP via `ProductCostPriceFactory`, making DB-level sorting/filtering on derived columns impossible. Pagination uses raw ints with no domain-level validation.

**Goals**:
1. Create PostgreSQL views (`catalog.products_view`, `catalog.product_variations_view`) that join products with Linnworks cost prices and pre-compute `profit_margin` — enabling DB-level sort/filter on derived columns
2. Introduce domain-level query primitives (pagination, sorting, filtering) that flow through all layers
3. Extend the existing `ProductListQueryParams` with sort/filter support

---

## Architecture: What Goes Where

| Abstraction | Layer | Namespace | Rationale |
|---|---|---|---|
| `PageRequest` | Domain | `Domain\Shared\Pagination\ValueObjects` | Universal concept, no framework deps. Parallels `Money` in `Domain\Shared` |
| `SortDirection` | Domain | `Domain\Shared\Pagination\Enums` | Generic asc/desc, reusable across all entities |
| `ProductSortField` | Domain | `Domain\Catalog\Product\Enums` | Describes intrinsic product properties |
| `ProductFilterField` | Domain | `Domain\Catalog\Product\Enums` | Describes which product properties are filterable |
| `catalog` schema | Database | `catalog` | New Postgres schema for read-model views |
| `catalog.products_view` | Database | `catalog` schema | Products + Linnworks cost prices + computed columns |
| `catalog.product_variations_view` | Database | `catalog` schema | Variations + parent price inheritance + Linnworks cost prices + computed columns |

**Already exists** (from #429): `ProductInclude` in `Domain\Catalog\Product\Enums`, `ProductListQueryParams` in `Application\Catalog\Queries`

---

## Phase 1: PostgreSQL Views — `catalog` Schema

### Why Views?

Currently `ProductViewAssembler` does a PHP-side join: it calls `ProductCostPriceFactory::getCostPrice($sku)` which lazy-loads ALL cost prices from `linnworks.stock_items + stock_item_suppliers` into memory. This works but:
- **Can't sort/filter by computed columns** (profit_margin, effective_price) at the DB level
- **N+1 risk** if the factory pattern is removed or changes
- **Duplicates join logic** between PHP and any future SQL needs

Postgres views push the join + computation into SQL, making computed columns first-class sortable/filterable fields.

### Why a New `catalog` Schema?

These views are read-model projections that span multiple source schemas (`shopwired` + `linnworks`). They don't belong in either source schema — they're a new domain concept. A dedicated `catalog` schema:
- Clearly separates read models from source tables
- Follows the existing multi-schema pattern (`shopwired`, `linnworks`, `operations`, `reviews_io`)
- Can be extended with more views later (orders_view, etc.)

### Migration 1: `create_catalog_schema.php`

Follows the `linnworks` schema migration pattern:

```php
DB::statement('CREATE SCHEMA IF NOT EXISTS catalog');
DB::statement('GRANT USAGE ON SCHEMA catalog TO authenticated, service_role');
DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog GRANT ALL ON TABLES TO service_role');
DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog GRANT SELECT, INSERT, UPDATE, DELETE ON TABLES TO authenticated');
DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog GRANT USAGE, SELECT ON SEQUENCES TO service_role');
DB::statement('ALTER DEFAULT PRIVILEGES IN SCHEMA catalog GRANT USAGE, SELECT ON SEQUENCES TO authenticated');
```

### Migration 2: `create_catalog_products_view.php`

#### `catalog.products_view`

```sql
CREATE VIEW catalog.products_view AS

-- Tax config: single source of truth for VAT rate (matches PHP TaxRate::standard() = 0.20)
WITH tax_config AS (
    SELECT 0.20 AS standard_vat_rate  -- UK VAT 20%
),

-- Pricing CTE: derive sale state, effective price, and net price
pricing AS (
    SELECT
        p.id,
        p.price,
        p.sale_price,
        p.vat_exclusive,
        (p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price) AS is_on_sale,
        CASE
            WHEN p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price
            THEN p.sale_price ELSE p.price
        END AS effective_price,
        -- Net effective price: VAT-inclusive products divide by (1 + rate), VAT-exclusive use raw value
        CASE
            WHEN p.sale_price IS NOT NULL AND p.sale_price > 0 AND p.sale_price < p.price
            THEN CASE WHEN p.vat_exclusive THEN p.sale_price ELSE p.sale_price / (1 + tc.standard_vat_rate) END
            ELSE CASE WHEN p.vat_exclusive THEN p.price ELSE p.price / (1 + tc.standard_vat_rate) END
        END AS effective_price_net
    FROM shopwired.products p
    CROSS JOIN tax_config tc
)

-- Main: all product columns + pricing enrichments + Linnworks cost + profit margin
SELECT
    p.id,
    p.external_id,
    p.sku,
    p.gtin,
    p.title,
    p.description,
    p.slug,
    p.url,
    p.price,
    p.sale_price,
    p.compare_price,
    p.stock,
    p.is_active,
    p.vat_exclusive,
    p.vat_relief,
    p.weight,
    p.meta_title,
    p.meta_description,
    p.category_ids,
    p.images,
    p.custom_fields,
    p.filters,
    p.sort_order,
    p.shopwired_created_at,
    p.shopwired_updated_at,
    p.created_at,
    p.updated_at,

    -- From pricing CTE
    pr.is_on_sale,
    pr.effective_price,

    -- Linnworks cost price (from default supplier — always tax-exclusive)
    s.purchase_price AS cost_price,

    -- Profit margin: (net_price - net_cost) / net_price × 100
    -- Uses net values to match PHP: ProductView::retailMargin()
    -- Cost price is already net (Linnworks prices are tax-exclusive)
    CASE
        WHEN s.purchase_price IS NOT NULL AND pr.effective_price_net > 0
        THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
        ELSE NULL
    END AS profit_margin

FROM shopwired.products p
INNER JOIN pricing pr ON pr.id = p.id
LEFT JOIN linnworks.stock_items si
    ON si.item_number = p.sku
    AND si.item_number IS NOT NULL
    AND si.item_number != ''
LEFT JOIN linnworks.stock_item_suppliers s
    ON s.stock_item_id = si.stock_item_id
    AND s.is_default = true
    AND s.purchase_price IS NOT NULL
```

Note: The raw `p.cost_price` (ShopWired's cost price) is intentionally excluded — dead code, never used by the domain. The view's `cost_price` column is the Linnworks cost price.

#### `catalog.product_variations_view`

Companion view with the same computed columns. Uses a 2-CTE pipeline:
1. `base_pricing` — resolves price inheritance from parent (`COALESCE`)
2. `pricing` — derives `is_on_sale` + `effective_price` from resolved values

The outer SELECT is then nearly identical to the products view — just Linnworks join + profit_margin.

```sql
CREATE VIEW catalog.product_variations_view AS

-- Tax config: single source of truth for VAT rate (matches PHP TaxRate::standard() = 0.20)
WITH tax_config AS (
    SELECT 0.20 AS standard_vat_rate  -- UK VAT 20%
),

-- Pricing CTE 1: resolve price inheritance from parent
base_pricing AS (
    SELECT
        v.id,
        COALESCE(v.price, p.price) AS price,
        COALESCE(v.sale_price, p.sale_price) AS sale_price,
        p.vat_exclusive
    FROM shopwired.product_variations v
    INNER JOIN shopwired.products p ON p.id = v.product_id
),

-- Pricing CTE 2: derive sale state, effective price, net price (same logic as products_view)
pricing AS (
    SELECT
        bp.id,
        bp.price,
        bp.sale_price,
        (bp.sale_price IS NOT NULL AND bp.sale_price > 0 AND bp.sale_price < bp.price) AS is_on_sale,
        CASE
            WHEN bp.sale_price IS NOT NULL AND bp.sale_price > 0 AND bp.sale_price < bp.price
            THEN bp.sale_price ELSE bp.price
        END AS effective_price,
        CASE
            WHEN bp.sale_price IS NOT NULL AND bp.sale_price > 0 AND bp.sale_price < bp.price
            THEN CASE WHEN bp.vat_exclusive THEN bp.sale_price ELSE bp.sale_price / (1 + tc.standard_vat_rate) END
            ELSE CASE WHEN bp.vat_exclusive THEN bp.price ELSE bp.price / (1 + tc.standard_vat_rate) END
        END AS effective_price_net
    FROM base_pricing bp
    CROSS JOIN tax_config tc
)

-- Main: all variation columns + pricing enrichments + Linnworks cost + profit margin
SELECT
    v.id,
    v.product_id,
    v.product_external_id,
    v.external_id,
    v.sku,
    v.stock,
    v.weight,
    v.gtin,
    v.mpn,
    v.image_index,
    v.options,
    v.created_at,
    v.updated_at,

    -- Raw prices (before inheritance — for debugging)
    v.price AS raw_price,
    v.sale_price AS raw_sale_price,

    -- Resolved prices (from pricing CTEs)
    pr.price,
    pr.sale_price,
    pr.is_on_sale,
    pr.effective_price,

    -- Linnworks cost price (by variation's own SKU)
    s.purchase_price AS cost_price,

    -- Profit margin: (net_price - net_cost) / net_price × 100 (same formula as products_view)
    CASE
        WHEN s.purchase_price IS NOT NULL AND pr.effective_price_net > 0
        THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
        ELSE NULL
    END AS profit_margin

FROM shopwired.product_variations v
INNER JOIN pricing pr ON pr.id = v.id
LEFT JOIN linnworks.stock_items si
    ON si.item_number = v.sku
    AND si.item_number IS NOT NULL
    AND si.item_number != ''
LEFT JOIN linnworks.stock_item_suppliers s
    ON s.stock_item_id = si.stock_item_id
    AND s.is_default = true
    AND s.purchase_price IS NOT NULL
```

**CTE pipeline pattern**: Both views use the same structure — pricing logic lives in focused CTEs, the main SELECT joins base columns + pricing + Linnworks. Variations just prepend a `base_pricing` CTE for parent inheritance. When adding new computed columns:
- Depends on resolved prices → add to `pricing` CTE
- Depends on cost price → add to main SELECT
- Needs a new data source → add a new LEFT JOIN in the main SELECT

Note: `v.cost_price` (ShopWired's sentinel-based cost price: -1.0=inherit, 0.00=unknown) is excluded. The view's `cost_price` is the Linnworks cost price by variation SKU.

### What the Views Add

| Column | Source | On View |
|---|---|---|
| `cost_price` | `stock_item_suppliers.purchase_price` via SKU join | Both |
| `effective_price` | `CASE` on `sale_price` vs `price` | Both |
| `is_on_sale` | `sale_price IS NOT NULL AND > 0 AND < price` | Both |
| `profit_margin` | `(effective_price_net - cost) / effective_price_net * 100` | Both |
| `raw_price` / `raw_sale_price` | Pre-inheritance variation prices | Variations only |
| `price` / `sale_price` | Resolved via `COALESCE` from parent | Variations only (overrides raw) |

### Impact on Assembler & Factory

After the views:
- **`ProductCostPriceFactory` is removed from the read path entirely** — both product and variation cost prices come from their respective views
- **`ProductViewAssembler`**: reads `$model->cost_price` directly (no factory call)
- **`ProductVariationModelMapper::toViewDomain()`**: reads `$model->cost_price` directly (no factory call)
- **`ProductCostPriceFactory` may still be needed** for write-path use cases that need cost prices outside of views (check during implementation — if no other callers, the factory can be deleted)

### Eager-Loading: View → View

`ProductViewModel` defines the same `variations()` relationship as `ProductModel`, but targets the variations view model:

```php
// ProductViewModel
public function variations(): HasMany
{
    return $this->hasMany(ProductVariationViewModel::class, 'product_id', 'id');
}
```

Eloquent eager-loads identically on views: `SELECT * FROM catalog.product_variations_view WHERE product_id IN (?,?,...)`. Each variation row already has `cost_price` from the Linnworks join — zero N+1.

### New Eloquent Models

**`ProductViewModel`** — read-only, points at `catalog.products_view`:
```php
final class ProductViewModel extends Model
{
    protected $table = 'catalog.products_view';
    public $timestamps = false;
    protected $guarded = [];
    // Same casts as ProductModel + cost_price, effective_price, is_on_sale, profit_margin
}
```

**`ProductVariationViewModel`** — read-only, points at `catalog.product_variations_view`:
```php
final class ProductVariationViewModel extends Model
{
    protected $table = 'catalog.product_variations_view';
    public $timestamps = false;
    protected $guarded = [];
    // Same casts as ProductVariationModel + cost_price
}
```

Both are used only by the read path. Write paths continue using `ProductModel` / `ProductVariationModel`.

---

## Phase 2: Domain Shared Primitives

### 2a. `app/Domain/Shared/Pagination/ValueObjects/PageRequest.php`

```php
final readonly class PageRequest
{
    private const int MAX_PER_PAGE = 1000;

    private function __construct(
        public int $page,
        public int $perPage,
    ) {
        Assert::positiveInteger($page);
        Assert::positiveInteger($perPage);
        Assert::lessThanEq($perPage, self::MAX_PER_PAGE);
    }

    public static function from(int $page, int $perPage): self { ... }
    public static function firstPage(int $perPage = 500): self { ... }
}
```

### 2b. `app/Domain/Shared/Pagination/Enums/SortDirection.php`

```php
enum SortDirection: string
{
    case Asc = 'asc';
    case Desc = 'desc';
}
```

---

## Phase 3: Product Domain Enums

### 3a. `app/Domain/Catalog/Product/Enums/ProductSortField.php`

```php
enum ProductSortField: string
{
    case Title = 'title';
    case Price = 'price';
    case CreatedAt = 'created_at';
    case UpdatedAt = 'updated_at';
    case Stock = 'stock';
    case ProfitMargin = 'profit_margin';

    /** Database column name for this sort field (matches products_view columns). */
    public function column(): string
    {
        return match ($this) {
            self::CreatedAt => 'shopwired_created_at',
            self::UpdatedAt => 'shopwired_updated_at',
            default => $this->value,
        };
    }
}
```

All values map directly to `products_view` columns. `profit_margin` is a real column on the view — no special SQL handling needed.

### 3b. `app/Domain/Catalog/Product/Enums/ProductFilterField.php`

```php
enum ProductFilterField: string
{
    case IsActive = 'is_active';
    case CategoryId = 'category_id';
    case IsOnSale = 'is_on_sale';
    case Sku = 'sku';
}
```

`IsOnSale` maps to the view's computed `is_on_sale` boolean column — no complex WHERE clause needed.

---

## Phase 4: Extend `ProductListQueryParams`

The existing `ProductListQueryParams` (from #429) has `int $perPage`, `int $page`, `list<ProductInclude> $includes`. Extend it with pagination VO, sort, and filter support:

```php
final readonly class ProductListQueryParams
{
    /**
     * @param list<ProductInclude> $includes
     * @param array<value-of<ProductFilterField>, mixed> $filters
     */
    public function __construct(
        public PageRequest $pagination,
        public array $includes = [],
        public ?ProductSortField $sortField = null,
        public SortDirection $sortDirection = SortDirection::Asc,
        public array $filters = [],
    ) {}

    /** Default: active products, sorted by title. */
    public static function active(PageRequest $pagination, array $includes = []): self
    {
        return new self(
            pagination: $pagination,
            includes: $includes,
            sortField: ProductSortField::Title,
            sortDirection: SortDirection::Asc,
            filters: [ProductFilterField::IsActive->value => true],
        );
    }

    public function hasInclude(ProductInclude $include): bool
    {
        return \in_array($include, $this->includes, true);
    }
}
```

**Breaking change**: `$perPage` and `$page` replaced by `$pagination: PageRequest`. All callers update.

---

## Phase 5: Update Repository & Assembler

### 5a. Modify `EloquentProductRepository::paginate()`

Switch from `ProductModel` (raw table) to `ProductViewModel` (view with computed columns), and add dynamic scope building:

```php
public function paginate(ProductListQueryParams $query): PaginatedListDTO
{
    return $this->eloquentGateway->paginate(
        modelClass: ProductViewModel::class,
        scope: self::buildScope($query),
        relations: $query->hasInclude(ProductInclude::Variations) ? ['variations'] : [],
        mapper: fn(ProductViewModel $model): ProductView => $this->viewMapper->toViewDomain($model, $query->includes),
        perPage: $query->pagination->perPage,
        page: $query->pagination->page,
    );
}

private static function buildScope(ProductListQueryParams $query): Closure
{
    return static function (Builder $q) use ($query): void {
        // Filters — all map to view columns directly
        foreach ($query->filters as $field => $value) {
            match (ProductFilterField::from($field)) {
                ProductFilterField::IsActive => $q->where('is_active', $value),
                ProductFilterField::CategoryId => $q->whereJsonContains('category_ids', $value),
                ProductFilterField::IsOnSale => $q->where('is_on_sale', $value),
                ProductFilterField::Sku => $q->where('sku', $value),
            };
        }

        // Sorting — all columns exist on the view
        if ($query->sortField !== null) {
            $q->orderBy($query->sortField->column(), $query->sortDirection->value);
        }
    };
}
```

**EloquentGateway**: No changes needed.

### 5b. Update `ProductViewAssembler`

The assembler receives a `ProductViewModel` (which has `cost_price` from the view join) instead of a `ProductModel` + factory lookup:

```php
// Before
costPrice: Money::nonZeroOrNull($this->getLinnworksCostPrice($model->sku), TaxType::Exclusive),

// After
costPrice: Money::nonZeroOrNull($model->cost_price, TaxType::Exclusive),
```

Remove `getLinnworksCostPrice()` method and `ProductCostPriceFactory` dependency from the assembler.

### 5c. Update `ProductVariationModelMapper::toViewDomain()`

```php
// Before
costPrice: $model->sku !== null ? Money::nonZeroOrNull($this->costPriceFactory->getCostPrice($model->sku), TaxType::Exclusive) : null,

// After (model is now ProductVariationViewModel with cost_price from view)
costPrice: Money::nonZeroOrNull($model->cost_price, TaxType::Exclusive),
```

Remove `ProductCostPriceFactory` dependency from `ProductVariationModelMapper`.

---

## Phase 6: Update Use Case & Logging

### 6a. Update `ListProductsUseCase::execute()` logging

The method signature already accepts `ProductListQueryParams`. Update logging to include new fields:

```php
$this->logger->info('Listing products', [
    'page' => $query->pagination->page,
    'per_page' => $query->pagination->perPage,
    'includes' => $query->includes,
    'sort' => $query->sortField?->value,
    'direction' => $query->sortDirection->value,
]);
```

---

## Phase 7: Update Presentation Layer

### 7a. Modify `ListProductsRequestDTO`

Add optional `sort_by` and `sort_direction` query params with validation. Bump `Max(500)` → `Max(1000)`. Add `toQuery(): ProductListQueryParams`:

```php
public function toQuery(): ProductListQueryParams
{
    return new ProductListQueryParams(
        pagination: PageRequest::from(page: $this->page, perPage: $this->per_page),
        includes: \array_map(ProductInclude::fromValue(...), $this->validatedIncludes()),
        sortField: $this->sort_by !== null ? ProductSortField::from($this->sort_by) : ProductSortField::Title,
        sortDirection: $this->sort_direction !== null ? SortDirection::from($this->sort_direction) : SortDirection::Asc,
        filters: [ProductFilterField::IsActive->value => true],
    );
}
```

### 7b. Simplify `ProductController::index()`

Currently constructs the query object inline. Replace with `toQuery()`:

```php
public function index(ListProductsRequestDTO $data): ResourceCollection
{
    $result = $this->listProductsUseCase->execute($data->toQuery());
    return $this->paginatedResponse($result, ProductResource::class);
}
```

### 7c. Bump `Max(500)` → `Max(1000)` on all list DTOs

- `ListProductsRequestDTO`
- `ListBrandsRequestDTO`
- `ListCategoriesRequestDTO`
- `ListFilterGroupsRequestDTO`

---

## Phase 8: Tests

- **Unit**: `PageRequest` (validation, factories), `ProductListQueryParams` (`active()` factory, `hasInclude()`)
- **Update existing**: `ListProductsUseCaseTest` mock expectations, `ProductControllerTest` feature tests
- **Integration**: Verify the full HTTP flow with `sort_by=profit_margin&sort_direction=desc`

---

## Files to Create (9)

| # | File |
|---|---|
| 1 | `database/migrations/XXXX_create_catalog_schema.php` |
| 2 | `database/migrations/XXXX_create_catalog_products_views.php` (both views in one migration) |
| 3 | `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php` |
| 4 | `app/Infrastructure/Catalog/Product/Models/ProductVariationViewModel.php` |
| 5 | `app/Domain/Shared/Pagination/ValueObjects/PageRequest.php` |
| 6 | `app/Domain/Shared/Pagination/Enums/SortDirection.php` |
| 7 | `app/Domain/Catalog/Product/Enums/ProductSortField.php` |
| 8 | `app/Domain/Catalog/Product/Enums/ProductFilterField.php` |
| 9 | Tests for new classes |

## Files to Modify (9)

| # | File | Change |
|---|---|---|
| 1 | `app/Application/Catalog/Queries/ProductListQueryParams.php` | Replace raw ints with `PageRequest`, add sort/filter fields, add `active()` + `hasInclude()` |
| 2 | `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` | Use `ProductViewModel`, dynamic `buildScope()`, use `$query->pagination->*` |
| 3 | `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` | Read `cost_price` from view model, remove `ProductCostPriceFactory` dependency |
| 4 | `app/Infrastructure/Catalog/Product/Mappers/ProductVariationModelMapper.php` | Read `cost_price` from view model, remove `ProductCostPriceFactory` dependency |
| 5 | `app/Application/Catalog/UseCases/ListProductsUseCase.php` | Update logging to use `$query->pagination->*` and sort fields |
| 6 | `app/Presentation/Http/Api/DTOs/ListProductsRequestDTO.php` | Add sort/filter params, `toQuery()`, `Max(1000)` |
| 7 | `app/Presentation/Http/Api/Controllers/ProductController.php` | Use `$data->toQuery()` instead of inline construction |
| 8 | `app/Presentation/Http/Api/DTOs/ListBrandsRequestDTO.php` | `Max(500)` → `Max(1000)` |
| 9 | `app/Presentation/Http/Api/DTOs/ListCategoriesRequestDTO.php` | `Max(500)` → `Max(1000)` |

---

## Verification

1. `php artisan migrate` — Views create successfully
2. `make lint` — Deptrac/PHPArkitect/PHPStan pass
3. `make test` — All existing tests pass with updated mocks
4. Manual: `GET /api/products?sort_by=profit_margin&sort_direction=desc&per_page=10` — sorted by margin
5. Manual: `GET /api/products?sort_by=price&sort_direction=asc` — sorted by price
6. Manual: `GET /api/products?sort_by=invalid` — 422 validation error

---

## PHP ↔ SQL Pricing Verification

Verified that view calculations match existing PHP domain logic:

| Calculation | PHP Source | SQL Equivalent | Status |
|---|---|---|---|
| `is_on_sale` | `ProductView::isSaleActive()` — `!= null && != 0 && < price` | `IS NOT NULL AND > 0 AND < price` | ✅ Match |
| `effective_price` | `ProductRetailPricing::effectivePrice()` — sale if non-null & non-zero | sale if non-null & `> 0` & `< price` | ⚠️ SQL adds `< price` guard — more defensive, prevents nonsensical "sale" where salePrice > price |
| `effective_price_net` | `Money::toNet()` — divides Inclusive by `1 + rate` (UK VAT) | `CASE WHEN vat_exclusive THEN ... ELSE ... / (1 + tc.standard_vat_rate) END` via `tax_config` CTE | ✅ Match |
| `profit_margin` | `ProductView::retailMargin()` — `(price_net - cost_net) / price_net * 100` | `(effective_price_net - purchase_price) / effective_price_net * 100` | ✅ Match (cost is always tax-exclusive) |
| Variation price inheritance | `VariationPriceResolver::resolve()` — `$v->price ?? $parentPrice` | `COALESCE(v.price, p.price)` in base_pricing CTE | ✅ Match |
| `hasAnySale` | Aggregates `isOnSale` across product + variations | Not in view — stays in PHP (include-conditional) | ✅ Correct |

**Note on `effective_price` edge case**: The PHP `ProductRetailPricing::effectivePrice()` returns salePrice when non-null/non-zero even if salePrice >= price. The SQL requires `sale_price < price`. This only affects data errors (sale price set higher than regular price). The SQL is more correct for margin calculations and the discrepancy is flagged for awareness.

---

## Design Decisions & Trade-offs

### View vs Materialized View
Using a regular VIEW (not MATERIALIZED). The underlying data changes frequently (stock, prices) and the join is lightweight (indexed SKU lookup). A materialized view would add refresh complexity for minimal gain at our data volume.

### Read Model vs Write Model Split
`ProductViewModel` (view) is read-only for API queries. `ProductModel` (table) remains for writes. This is a natural CQRS-lite separation — the view is the "read model" and the table is the "write model".

### Assembler Stays (Simplified)
The `ProductViewAssembler` still handles:
- Value object construction (`Money`, `Sku`, `IntId`, etc.)
- Conditional include resolution (variations, custom fields, filters, sale settings)
- PHP-only logic (custom field type resolution, filter typing)

What it no longer does:
- Cost price lookup (now on the view)

### Variation Cost Prices
Both views handle their own Linnworks cost price join. `ProductCostPriceFactory` is removed from the entire read path (assembler + variation mapper). Check during implementation whether any write-path code still uses the factory — if not, it can be deleted entirely.

---

## Future: Replicating for Other Entities

When brands/categories need the same treatment:
1. Reuse `PageRequest` and `SortDirection` from `Domain\Shared\Pagination\`
2. Create entity-specific enums in `Domain\Catalog\{Entity}\Enums\`
3. Create `{Entity}ListQueryParams` in `Application\Catalog\Queries\`
4. Optionally create a Postgres view if computed columns are needed

The view pattern is optional — only use it when there's a cross-table join or computed column need. Simple entities (brands, categories) may not need one.
