# Plan: Add Supplier Data to ProductVariationView

## Context

Products with variations are missing supplier data in API responses. The product-level `defaultSupplier` is derived from `ProductViewModel → stockItem (matched by master SKU) → suppliers`, but each variation has its **own SKU** that maps to its own `StockItem` in Linnworks. The variation pipeline never loads a `stockItem`, so variation-level supplier data is completely absent — breaking the front-end for products with variants.

## Changes (6 files)

### 1. Domain: `ProductVariationView` — add supplier properties
**File:** `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php`

Add two optional constructor params (matching ProductView's pattern):
```php
?ProductSupplier $defaultSupplier = null,
/** @var list<ProductSupplier>|null */
?array $suppliers = null,
```
These are nullable because suppliers may not be loaded (same as product-level).

### 2. Infrastructure: `ProductVariationViewModel` — add stockItem relation
**File:** `app/Infrastructure/Catalog/Product/Models/ProductVariationViewModel.php`

Add a `stockItem()` HasOne relation matching by variation SKU:
```php
public function stockItem(): HasOne
{
    return $this->hasOne(StockItemModel::class, 'item_number', 'sku');
}
```
This mirrors `ProductViewModel::stockItem()` (line 164-167) — same join column, different source model.

### 3. Infrastructure: `ProductVariationModelMapper::toViewDomain()` — accept supplier params
**File:** `app/Infrastructure/Catalog/Product/Mappers/ProductVariationModelMapper.php`

Add optional `$defaultSupplier` and `$suppliers` parameters, pass through to VO constructor:
```php
public function toViewDomain(
    ProductVariationViewModel $model,
    bool $vatExclusive,
    ?ProductSupplier $defaultSupplier = null,
    ?array $suppliers = null,
): ProductVariationView {
    return new ProductVariationView(
        // ... existing params ...
        defaultSupplier: $defaultSupplier,
        suppliers: $suppliers,
    );
}
```

### 4. Infrastructure: `ProductViewAssembler::resolveVariations()` — resolve per-variation suppliers
**File:** `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`

Update `resolveVariations()` to accept `$includes` (already does) and resolve suppliers per variation:
- Always derive `defaultSupplier` from `$variation->stockItem->defaultSupplier()?->toProductSupplier()` (no include check — matches product-level pattern)
- Resolve full `suppliers` list only when `ProductInclude::Suppliers` is in includes
- Pass both to the mapper

### 5. Infrastructure: `EloquentProductRepository` — eager load (TWO sites)
**File:** `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

**5a. `relationsForIncludes()`** — add conditional eager loading for the paginate (list) path:
```php
if ($has(ProductInclude::Variations)) {
    $relations[] = 'variations.stockItem.suppliers';
}
```

**5b. `findProductView()`** (line 139) — add to the hardcoded always-load array:
```php
relations: array_values(array_unique([
    'variations',
    'variations.extraData',
    'variations.stockItem.suppliers',  // ← ADD THIS
    ...self::relationsForIncludes($query->includes),
]))
```
**Why both?** `findProductView()` always loads variations regardless of includes (the detail endpoint always serializes them). If we only add to `relationsForIncludes()`, detail requests without `ProductInclude::Variations` would eager-load variations but NOT their stock items — causing `defaultSupplier` to be null for all variations.

This avoids N+1 queries — Eloquent batches into ~2-3 queries regardless of variation count.

### 6. Presentation: `ProductVariationResource` — serialize supplier fields
**File:** `app/Presentation/Http/Api/Resources/ProductVariationResource.php`

Add to `toArray()`:
```php
'default_supplier' => $variation->defaultSupplier?->toArray(),
```
And conditionally include full suppliers list when loaded:
```php
if ($variation->suppliers !== null) {
    $data['suppliers'] = array_map(fn(ProductSupplier $s) => $s->toArray(), $variation->suppliers);
}
```

## Verification

1. `make lint` — ensure PHPStan/Pint/Arkitect/Deptrac pass
2. `make test` — run existing test suite (no regressions)
3. Manual API test: `GET /api/products/{id}?include=variations,suppliers` with `X-Local-Bypass` header — verify each variation now has `default_supplier` and `suppliers` in the response
4. Test with a product that has variations with different SKUs mapped to different Linnworks stock items with different default suppliers
