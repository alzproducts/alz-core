# Variation Endpoint Enhancements

## Context

The `GET /api/products/variations` endpoint (shipped in COR-705/712) needs sorting, filtering, bug fixes, and a new column before the frontend can use it as the primary variations table. These changes complete the endpoint's feature set so the frontend can drop its client-side supplier filter and gain server-side sorting and stock-awareness.

## Bug Fixes

### 1. Image index off-by-one
**File:** `app/Domain/Catalog/Product/ValueObjects/VariationListItem.php` (lines 76-87)

`resolveImage()` treats `$imageIndex` as 0-based but ShopWired stores it 1-based. Fix: subtract 1 before lookup, guard `< 1`. Pattern already proven in `VariationImageResolver::resolve()` (line 50).

### 2. Remove parentSku (always empty)
Parent products with variations don't carry their own SKU. Remove property + constructor param + serialisation:

| File | Change |
|------|--------|
| `app/Domain/Catalog/Product/ValueObjects/VariationListItem.php` | Remove `$parentSku` property, `$parentSkuRaw` param, constructor body |
| `app/Infrastructure/Catalog/Product/Mappers/VariationListAssembler.php` | Remove `parentSkuRaw: $parent->sku` (line 60) |
| `app/Presentation/Http/Api/Resources/VariationListResource.php` | Remove `'parent_sku'` (line 88) |

## SQL View Migration

**Create:** `database/migrations/2026_05_06_100001_add_supplier_name_and_stock_value_to_catalog_product_variations_view.php`

DROP + CREATE of `catalog.product_variations_view`. Adds two columns to the SELECT (no new JOINs — `s` alias already joined):
- `s.supplier_name AS default_supplier_name`
- `s.purchase_price * COALESCE(si.available, v.stock, 0) AS stock_value` (NULL when `s.purchase_price IS NULL`)

`down()` copies the `up()` body of `2026_05_02_100001_expand_catalog_product_variations_view_with_parent_columns.php` verbatim — that is the live view definition we are replacing.

**Note:** The existing JOIN condition `AND s.purchase_price IS NOT NULL` means `default_supplier_name` is NULL when the supplier has no cost price set. This is acceptable — filtering by supplier only makes sense when cost data exists.

## Sorting: Popularity

| File | Change |
|------|--------|
| `app/Domain/Catalog/Product/Enums/VariationSortField.php` | Add `case Popularity = 'popularity'` |
| `app/Infrastructure/Catalog/Product/Mappers/VariationSortFieldMapper.php` | Map `Popularity` to `'popularity_rank'` |
| `app/Infrastructure/Catalog/Product/Repositories/EloquentVariationQueryRepository.php` | Special-case popularity in `buildScope()` to use `orderByRaw("{$column} {$direction} NULLS LAST")` — Eloquent `orderBy()` doesn't support NULLS LAST |

Ascending = most popular first (rank 1). NULLs always last (both directions).

`orderByRaw` is safe: `$column` from mapper (hardcoded string), `$direction` from PHP enum value (`'asc'`/`'desc'`).

## New Filters

### In Stock (boolean)
- DTO param: `?bool $in_stock = null`
- Enum: `VariationFilterField::InStock = 'in_stock'`
- SQL: `WHERE available_stock > 0` (true) / `= 0` (false)

### Default Supplier (string, exact match)
- DTO param: `?string $default_supplier = null`
- Enum: `VariationFilterField::DefaultSupplier = 'default_supplier'`
- SQL: `WHERE default_supplier_name = ?` (new view column from migration)

### Popularity Bucket (enum)
- **New file:** `app/Domain/Catalog/Product/Enums/PopularityBucket.php`
  - `MostPopular = 'most_popular'` -> rank 1-3
  - `LeastPopular = 'least_popular'` -> rank 10-12
- DTO param: `?string $popularity_bucket = null` with validation `in:most_popular,least_popular`
- Enum: `VariationFilterField::PopularityBucket = 'popularity_bucket'`
- SQL: `whereBetween('popularity_rank', [1, 3])` or `[10, 12]`

**All three filters follow the existing flat-param pattern** in `ListVariationsRequestDTO`: constructor property + `buildFilters()` entry + match arm in `buildScope()`.

The `match` in `EloquentVariationQueryRepository::buildScope()` has no `default` arm, so PHPStan max requires all three new arms to be added at the same time:

```php
VariationFilterField::InStock           => $q->where('available_stock', $value ? '>' : '=', 0),
VariationFilterField::DefaultSupplier   => $q->where('default_supplier_name', $value),
VariationFilterField::PopularityBucket  => $q->whereBetween(
    'popularity_rank',
    PopularityBucket::from($value) === PopularityBucket::MostPopular ? [1, 3] : [10, 12],
),
```

## New Column: Stock Value

`stockValue` belongs on `ProductVariationView` (not `Stock` VO — Stock is a pure quantity-pair, adding Money would break its summation semantics).

| File | Change |
|------|--------|
| `app/Infrastructure/Catalog/Product/Models/ProductVariationViewModel.php` | Add `@property float\|null $stock_value` + cast |
| `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php` | Append `?float $stockValue = null` as the **last** constructor param (after `?Popularity $popularity = null`) so existing positional/named callers stay valid. Set `$this->stockValue = Money::nonZeroOrNull($stockValue, TaxType::Exclusive)` — matches the codebase convention used for `costPrice` / `salePrice` / `rrp`. |
| `app/Infrastructure/Catalog/Product/Mappers/ProductVariationViewModelMapper.php` | Pass `stockValue: $model->stock_value` in `toViewDomain()` |
| `app/Presentation/Http/Api/Resources/VariationListResource.php` | Add `'stock_value' => $variation->stockValue?->toNet()` inside `stock` block |

Default `null` param means existing call sites (product detail path, tests) are unaffected.

## Implementation Order

1. **Migration** (foundation for supplier filter + stock_value)
2. **Bug fixes** (image index + parentSku removal) — independent
3. **Popularity sort** (enum + mapper + repository)
4. **Filters** (in_stock, default_supplier, popularity_bucket) — all touch the same 3 files
5. **Stock value column** (model + VO + mapper + resource)

## Files Changed (complete list)

| File | Action |
|------|--------|
| `database/migrations/2026_05_06_100001_...catalog_product_variations_view.php` | CREATE |
| `app/Domain/Catalog/Product/Enums/PopularityBucket.php` | CREATE |
| `app/Domain/Catalog/Product/ValueObjects/VariationListItem.php` | MODIFY |
| `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php` | MODIFY |
| `app/Domain/Catalog/Product/Enums/VariationSortField.php` | MODIFY |
| `app/Domain/Catalog/Product/Enums/VariationFilterField.php` | MODIFY |
| `app/Infrastructure/Catalog/Product/Mappers/VariationListAssembler.php` | MODIFY |
| `app/Infrastructure/Catalog/Product/Mappers/VariationSortFieldMapper.php` | MODIFY |
| `app/Infrastructure/Catalog/Product/Mappers/ProductVariationViewModelMapper.php` | MODIFY |
| `app/Infrastructure/Catalog/Product/Models/ProductVariationViewModel.php` | MODIFY |
| `app/Infrastructure/Catalog/Product/Repositories/EloquentVariationQueryRepository.php` | MODIFY |
| `app/Presentation/Http/Api/DTOs/ListVariationsRequestDTO.php` | MODIFY |
| `app/Presentation/Http/Api/Resources/VariationListResource.php` | MODIFY |

## Verification

1. `php artisan migrate` — view recreated with new columns
2. `make lint` — PHPStan, Pint, PHPArkitect, Deptrac pass
3. `make test` — existing tests pass (default params protect existing call sites)
4. Manual curl tests:
   - `?sort_by=popularity&sort_direction=asc` — most popular first, NULLs last
   - `?in_stock=true` — only available > 0
   - `?default_supplier=<name>` — exact match
   - `?popularity_bucket=most_popular` — rank 1-3 only
   - Verify `stock.stock_value` present in response
   - Verify `parent_sku` absent from response
   - Verify image is correct (not off-by-one)
