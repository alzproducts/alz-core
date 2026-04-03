# Plan: ProductInventory & ProductStock VOs for ProductView

## Context

**Issue**: Products API needs Linnworks inventory data (barcode, weight, dimensions, stock levels, JIT flag, etc.) exposed via `?include=inventory,stock` — replacing ShopWired as source of truth for `stock`, `gtin`, `weight`.

**#461 supplier stat persistence** (migration + model updates) is already complete on `develop`.

**Approach**: Eloquent `HasOne` relation from `ProductViewModel` → `StockItemModel` for the new includes. Child VOs self-construct from primitives (assembler passes raw model fields). No new factory classes.

---

## 1. New Domain VOs

### `app/Domain/Catalog/Product/ValueObjects/ProductInventory.php`

Self-constructing `final readonly class` — receives primitives, builds domain types internally.

**Constructor params (primitives):**
- `?string $barcode`
- `?int $minimumLevel`
- `?float $weight`
- `?string $weightUnit`
- `?float $height`, `?float $width`, `?float $depth`
- `bool $isComposite`
- `string $categoryName`

**Public properties (domain types, constructed internally):**
- `?Gtin $barcode` — `Gtin::fromString()` with try/catch, null on invalid/empty
- `?int $minimumLevel` — nullable (null = no data, 0 = min level is zero)
- `?Weight $weight` — null when `$weight` param is null, else `new Weight($weight, WeightUnit::tryFrom(...))`
- `?Dimensions $dimensions` — null when all three floats are null, else `new Dimensions(...)`
- `bool $isComposite`
- `string $categoryName`
- `toArray(): array` for API serialisation

### `app/Domain/Catalog/Product/ValueObjects/ProductStock.php`

Self-constructing `final readonly class` — receives primitives from StockItemModel.

**Constructor params (primitives):**
- `?int $quantity`
- `?int $available`
- `?int $inOrder`
- `?int $due`
- `bool $jit`

All quantity fields are `?int` — null means "Linnworks didn't provide this value", 0 means "zero stock". No coalescing. The assembler passes the raw nullable values straight through from `StockItemModel`.

- `toArray(): array`

---

## 2. Update ProductInclude Enum

**File**: `app/Domain/Catalog/Product/Enums/ProductInclude.php`

Add:
```php
case Inventory = 'inventory';
case Stock = 'stock';
```

---

## 3. Update ProductView — Remove ShopWired fields, Add New Includes

**File**: `app/Domain/Catalog/Product/ValueObjects/ProductView.php`

### Remove from constructor / base fields:
- `int $stock` (line 106) — replaced by `ProductStock`
- `?string $gtin` (line 94) + `public ?Gtin $gtin` property (line 31) — replaced by `ProductInventory.barcode`
- `?float $weight` (line 110) + `public ?Weight $weight` property (line 43) — replaced by `ProductInventory.weight`

### Add as nullable optional constructor params (like `$saleSettings`, `$suppliers`):
```php
public ?ProductInventory $inventory = null,
public ?ProductStock $stock = null,
```

### Impact on existing code:
- `ProductResource::baseFields()` — remove `stock`, `gtin`, `weight` from the base output
- All callers constructing `ProductView` — stop passing `stock`, `gtin`, `weight`
- `ProductVariationView` — **leave as-is** (out of scope per user decision)

---

## 4. Add Eloquent Relation: ProductViewModel → StockItemModel

**File**: `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php`

Add `HasOne` relationship:
```php
public function stockItem(): HasOne
{
    return $this->hasOne(StockItemModel::class, 'item_number', 'sku');
}
```

Cross-schema relation (`catalog.products_view` → `linnworks.stock_items`) is fine — Eloquent handles it, and Deptrac treats all Infrastructure as one layer.

---

## 5. Update Repository — Eager-Load stockItem

**File**: `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

Update `relationsForIncludes()` (line 449):
```php
private static function relationsForIncludes(array $includes): array
{
    $relations = [];
    if (\in_array(ProductInclude::Variations, $includes, true)) {
        $relations[] = 'variations';
    }
    if (\in_array(ProductInclude::Inventory, $includes, true)
        || \in_array(ProductInclude::Stock, $includes, true)) {
        $relations[] = 'stockItem';
    }
    return $relations;
}
```

This eager-loads stock items in a single `WHERE item_number IN (...)` query for the entire page.

---

## 6. Update ProductViewAssembler — Pass Primitives to Child VOs

**File**: `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`

### Remove from `toViewDomain()`:
- Stop passing `stock`, `gtin`, `weight` to `ProductView` constructor

### Add resolve methods (thin — just check include + pass raw model fields):
```php
private static function resolveInventory(ProductViewModel $model, array $includes): ?ProductInventory
{
    if (! \in_array(ProductInclude::Inventory, $includes, true)) {
        return null;
    }
    if (! $model->relationLoaded('stockItem') || $model->stockItem === null) {
        return null;
    }
    $si = $model->stockItem;

    return new ProductInventory(
        barcode: $si->barcode,
        minimumLevel: $si->minimum_level,
        weight: $si->weight,
        weightUnit: $si->weight_unit,
        height: $si->height,
        width: $si->width,
        depth: $si->depth,
        isComposite: $si->is_composite,
        categoryName: $si->category_name,
    );
}

private static function resolveStock(ProductViewModel $model, array $includes): ?ProductStock
{
    if (! \in_array(ProductInclude::Stock, $includes, true)) {
        return null;
    }
    if (! $model->relationLoaded('stockItem') || $model->stockItem === null) {
        return null;
    }
    $si = $model->stockItem;

    return new ProductStock(
        quantity: $si->quantity,
        available: $si->available,
        inOrder: $si->in_order,
        due: $si->due,
        jit: $si->jit,
    );
}
```

### Pass to ProductView constructor:
```php
inventory: self::resolveInventory($model, $includes),
stock: self::resolveStock($model, $includes),
```

Note: No `parseBarcode()` or `Weight` construction in the assembler — the VOs handle their own type construction internally.

---

## 7. Update Presentation — Detail Resource

**File**: `app/Presentation/Http/Api/Resources/ProductDetailResource.php`

Add to `scalarIncludes()`:
```php
if ($result->hasInclude(ProductInclude::Inventory) && $product->inventory !== null) {
    $data['inventory'] = $product->inventory->toArray();
}
if ($result->hasInclude(ProductInclude::Stock) && $product->stock !== null) {
    $data['stock'] = $product->stock->toArray();
}
```

---

## 8. Update Presentation — List Resource

**File**: `app/Presentation/Http/Api/Resources/ProductResource.php`

Remove from `baseFields()`: `stock`, `gtin`, `weight`

Add conditional includes to `toArray()`:
```php
if ($product->inventory !== null) {
    $data['inventory'] = $product->inventory->toArray();
}
if ($product->stock !== null) {
    $data['stock'] = $product->stock->toArray();
}
```

---

## 9. Update Request DTOs — Allow New Includes

### List endpoint
**File**: `app/Presentation/Http/Api/DTOs/ListProductsRequestDTO.php`

```php
public static function allowedIncludes(): array
{
    return [
        ProductInclude::Variations->value,
        ProductInclude::Inventory->value,
        ProductInclude::Stock->value,
    ];
}
```

### Detail endpoint
**File**: `app/Presentation/Http/Api/DTOs/ShowProductRequestDTO.php`

No change needed — `allowedIncludes()` already returns `ProductInclude::values()` (all enum cases), so new cases are automatically available.

---

## 10. Update SQL View — Stock Column Source

**File**: New migration `database/migrations/{timestamp}_update_catalog_products_view_stock_from_linnworks.php`

The `catalog.products_view` currently selects `p.stock` from ShopWired. Since Linnworks is the stock source of truth, update the view to source the `stock` column from `si.quantity` instead.

Change in the view's main SELECT:
```sql
-- Before:
p.stock,

-- After:
COALESCE(si.quantity, p.stock) AS stock,
```

Uses `COALESCE` so products without a Linnworks stock item fall back to ShopWired stock (avoids NULL for unmatched SKUs). This ensures `?sort_by=stock` sorts by Linnworks quantities, consistent with the `stock` include data.

**Note**: The `si` alias (`linnworks.stock_items`) is already joined in the view. Migration must `DROP VIEW` and re-`CREATE VIEW` (PostgreSQL doesn't support `ALTER VIEW` for column changes).

---

## 11. Update Tests

Files likely needing updates:
- `tests/Unit/Domain/Catalog/Product/ValueObjects/` — add tests for new VOs (self-construction, `toArray()`)
- Tests that construct `ProductView` — remove `stock`, `gtin`, `weight` params
- `ProductResource` / `ProductDetailResource` tests if any exist
- `ListProductsRequestDTO` test — verify new allowed includes

---

## Implementation Order

1. SQL view migration — update `stock` column to source from Linnworks
2. Domain VOs: `ProductInventory`, `ProductStock`
3. `ProductInclude` enum — add two cases
4. `ProductView` — remove `stock`/`gtin`/`weight`, add `inventory`/`stock`
5. `ProductViewModel` — add `stockItem()` relation
6. `EloquentProductRepository` — update `relationsForIncludes()`
7. `ProductViewAssembler` — remove old fields, add thin resolve methods
8. Presentation: `ProductResource`, `ProductDetailResource`
9. Request DTOs: `ListProductsRequestDTO`
10. Tests: update all affected tests

---

## Verification

1. `make lint` — all linters pass
2. `make test` — all tests pass
3. Manual: `GET /api/products?include=inventory,stock` returns correct structure
4. Manual: `GET /api/products/{id}?include=inventory,stock` returns correct structure
5. Manual: `GET /api/products` — no `stock`, `gtin`, `weight` in base response
6. Verify eager-loading: check query log shows single `WHERE item_number IN (...)` not N+1
7. Manual: Query all products with `?include=inventory,stock` — inspect for unexpected nulls

---

## Files to Modify

| File | Action |
|------|--------|
| `database/migrations/..._update_catalog_products_view_stock_from_linnworks.php` | **CREATE** — re-source `stock` from Linnworks |
| `app/Domain/Catalog/Product/ValueObjects/ProductInventory.php` | **CREATE** — self-constructing VO |
| `app/Domain/Catalog/Product/ValueObjects/ProductStock.php` | **CREATE** — self-constructing VO |
| `app/Domain/Catalog/Product/Enums/ProductInclude.php` | Add 2 cases (`Inventory`, `Stock`) |
| `app/Domain/Catalog/Product/ValueObjects/ProductView.php` | Remove 3 fields, add 2 nullable VOs |
| `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php` | Add `stockItem()` HasOne |
| `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` | Update `relationsForIncludes()` |
| `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` | Remove old fields, add thin resolve methods |
| `app/Presentation/Http/Api/Resources/ProductResource.php` | Remove base fields, add conditional includes |
| `app/Presentation/Http/Api/Resources/ProductDetailResource.php` | Add conditional includes |
| `app/Presentation/Http/Api/DTOs/ListProductsRequestDTO.php` | Update `allowedIncludes()` |
| `app/Presentation/Http/Api/DTOs/ShowProductRequestDTO.php` | No change (auto-inherits all enum cases) |
| Various test files | Update `ProductView` construction, add new VO tests |
