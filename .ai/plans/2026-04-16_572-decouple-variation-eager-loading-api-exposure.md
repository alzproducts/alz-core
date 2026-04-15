# Always Eager-Load Variations for Parent-Level Derivation

## Context

`defaultSupplier` is null on the ProductList endpoint when `?include=variations` isn't requested. Products without a direct `stockItem` (e.g., parent SKU has no Linnworks stock record — only variation SKUs do) derive `defaultSupplier` from their variations via `ProductVariationView::commonDefaultSupplier()`. But since variations aren't loaded without the explicit include, the fallback returns null.

**Root cause**: "what to load from DB" and "what to expose in API" are coupled — both gated by the `includes` array.

**Fix**: Decouple them. Always eager-load variations for internal derivation; only expose them in the API response when the client requests `?include=variations`.

## Changes

### 1. `EloquentProductRepository::relationsForIncludes()` — Always load variation relations

**File**: `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` (lines 449-475)

Move `variations`, `variations.extraData`, and `variations.stockItem.suppliers` out of the `ProductInclude::Variations` conditional:

```php
// Before: conditional on Variations include
if ($has(ProductInclude::Variations)) {
    $relations[] = 'variations';
}
// ...
if ($has(ProductInclude::Variations)) {
    $relations[] = 'variations.extraData';
    $relations[] = 'variations.stockItem.suppliers';
}

// After: always loaded for parent-level derivation (defaultSupplier, etc.)
$relations[] = 'variations';
$relations[] = 'variations.extraData';
$relations[] = 'variations.stockItem.suppliers';
```

### 2. `EloquentProductRepository::findProductView()` — Remove now-redundant prepend

**File**: Same file, line 139

The manual `array_unique(['variations', 'variations.extraData', 'variations.stockItem.suppliers', ...])` is now redundant since `relationsForIncludes()` always includes them. Simplify to just use `self::relationsForIncludes($query->includes)`.

### 3. `ProductViewMeta` — No changes

Stays exactly as-is. Business logic (`resolveCanEditRrp`, `resolveCanEditCostPrice`, `variationsHaveSameSellingPrice`) remains encapsulated. The only difference is *who* constructs it — moves from `ProductView` to the assembler.

### 4. `ProductView` — Accept pre-computed `ProductViewMeta` and `hasAnyVariationOnSale`

**File**: `app/Domain/Catalog/Product/ValueObjects/ProductView.php`

**a) `meta` — accept pre-constructed `ProductViewMeta`:**
- Add constructor param `ProductViewMeta $meta`
- Remove internal construction at line 143: `$this->meta = new ProductViewMeta($variations, $defaultSupplier, $isComposite);`
- Replace with: `$this->meta = $meta;`

**b) `hasAnySale` — accept pre-computed bool:**
- Change `anyVariationOnSale()` from `private static` to `public static` so the assembler can call it
- Add constructor param `bool $hasAnyVariationOnSale`
- Line 147 becomes: `$this->hasAnySale = $this->isOnSale || $hasAnyVariationOnSale;`

After these changes, the `ProductView` constructor has **zero dependency on `$this->variations`** for any derived computation. The `variations` param is purely for API exposure.

### 5. `ProductViewAssembler::toViewDomain()` — Gate API exposure, not internal resolution

**File**: `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` (lines 61-106)

Variations are now always loaded, so `resolveVariations()` always returns a list. The assembler constructs `ProductViewMeta` with the full variations, pre-computes `hasAnyVariationOnSale`, and gates what's passed to `ProductView::variations`:

```php
$variations = $this->resolveVariations($model, $includes);
$defaultSupplier = self::resolveDefaultSupplier($model, $variations);

// In ProductView constructor:
variations: \in_array(ProductInclude::Variations, $includes, true) ? $variations : null,
hasAnyVariationOnSale: ProductView::anyVariationOnSale($variations),
meta: new ProductViewMeta($variations, $defaultSupplier, $stockItem?->is_composite),
defaultSupplier: $defaultSupplier,
```

No changes needed to `resolveVariations()` itself — the `relationLoaded` guard stays as a defensive check.

## What doesn't change

- **`ProductViewMeta`** — no changes; just constructed in the assembler instead of ProductView
- **`ProductResource`** — `if ($product->variations !== null)` gating already correct
- **`ProductDetailResource`** — `$result->product->meta->toArray()` still works (meta always correct now)
- **`ProductVariationModelMapper`** — already handles all cases
- **`resolveDefaultSupplier()`** — already correct; it just always receives data now

## Performance

~4 additional `whereIn` queries per list page (not per product): `variations`, `variations.extraData`, `variations.stockItem`, `variations.stockItem.suppliers`. Variations are typically 2-10 per product. Negligible cost.

## Test updates

**File**: `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductViewTest.php`

- Update `createView()` helper to pass new `hasAnyVariationOnSale` param
- Restructure `hasAnySale` tests (lines 26-95): these currently verify the VO's internal derivation from variations. With the new param, the VO just stores `$isOnSale || $hasAnyVariationOnSale`. Update tests to match new semantics; test `anyVariationOnSale()` (now public static) directly for the variation-scanning logic.

## Verification

1. `make test` — update broken tests, then confirm all pass
2. Verify: list endpoint without `?include=variations` returns `defaultSupplier` for products that derive it from variations
3. Verify: `has_any_sale` is correct on list endpoint for products with variation-only sales
4. Verify: `variations` key is NOT in JSON response when include not requested
