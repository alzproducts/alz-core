# Implementation Log: #494 — Add gtin, variation mpn, and default supplier sub-object to product list API

## Issue Context
The product list API (`GET /api/products`) is missing `gtin` (barcode) and default supplier MPN. Variation `mpn` flows through the full domain chain but is silently dropped by the resource. The flat `?string $defaultSupplier` on `ProductView` is replaced with a structured `?ProductSupplier` sub-object.

**Success Criteria:**
- `gtin` appears on every product in the list response
- `default_supplier` appears as a full sub-object (null when no Linnworks match)
- `mpn` appears on each variation in list and detail responses
- `ProductView::$defaultSupplier` is `?ProductSupplier` (not `?string`); `findDefaultSupplierName()` removed
- Full `suppliers` array (via `?include=suppliers`) continues to work
- All linters and tests pass

## Implementation

### Sub-task 1: Variation mpn (one-liner)
- **File:** `app/Presentation/Http/Api/Resources/ProductVariationResource.php`
- Added `'mpn' => $variation->mpn,` after `gtin` in `toArray()`. Full chain (DB → VO → mapper → resource) already worked; only the resource was omitting it.

### Sub-task 2: Product gtin (thread through 3 layers)
- **File:** `app/Domain/Catalog/Product/ValueObjects/ProductView.php`
  - Added `public ?Gtin $gtin` property
  - Added `?string $gtin` constructor param (3rd position, after `sku`)
  - Added `$this->gtin = $gtin !== null && \mb_trim($gtin) !== '' ? Gtin::fromTrusted(\mb_trim($gtin)) : null;` assignment (mirroring sku pattern)
- **File:** `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`
  - Added `gtin: $model->gtin,` to the `new ProductView(...)` call
- **File:** `app/Presentation/Http/Api/Resources/ProductResource.php`
  - Added `'gtin' => $product->gtin?->value,` to `baseFields()` after `sku`

### Sub-task 3: Default supplier sub-object (replace ?string with ?ProductSupplier)
- **File:** `app/Domain/Catalog/Product/ValueObjects/ProductView.php`
  - Changed `public ?string $defaultSupplier` → `public ?ProductSupplier $defaultSupplier`
  - Added `?ProductSupplier $defaultSupplier = null` trailing optional constructor param
  - Changed body assignment from `self::findDefaultSupplierName($this->suppliers)` → `$defaultSupplier`
  - Removed `findDefaultSupplierName()` private method entirely
- **File:** `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`
  - Added `resolveDefaultSupplier(ProductViewModel $model): ?ProductSupplier` static private method using `$model->stockItem->defaultSupplier()?->toProductSupplier()`
  - Added `defaultSupplier: self::resolveDefaultSupplier($model),` to constructor call
- **File:** `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`
  - Replaced conditional `if ($has(ProductInclude::Suppliers))` block with unconditional `$relations[] = 'stockItem.suppliers';`
- **File:** `app/Presentation/Http/Api/Resources/ProductResource.php`
  - Added `'default_supplier' => $product->defaultSupplier?->toArray(),` to `baseFields()`

### Sub-task 4: Fix test constructors
- Added `gtin: null,` to 3 `ProductView` construction sites in tests:
  - `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductViewTest.php`
  - `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php` (2 places)

### Sub-task 5: Update complexity baseline
- Updated 4 existing entries in `phpstan-complexity-baseline.neon` with new line counts:
  - `ProductView.__construct()`: 50 → 53 lines
  - `ProductViewAssembler.toViewDomain()`: 37 → 39 lines
  - `EloquentProductRepository` class: 853 → 852 lines
  - `ProductResource.baseFields()`: 31 → 33 lines

## Test Results
All 2943 tests pass (6738 assertions).

## Lint Results
All linters pass:
- Pint: pass
- PHPStan: No errors
- PHPArkitect: No violations
- Deptrac: 0 violations
- TLint: LGTM

## Handoff Notes
- **No SQL migrations needed** — `p.gtin` already existed in the product SQL view
- **Always-loaded relation** — `stockItem.suppliers` is now unconditionally eager-loaded; the `Suppliers` include still works because the relation is pre-loaded
- **Derivation moved to assembler** — `defaultSupplier` is now resolved by the assembler using the always-loaded Eloquent relation, not derived inside `ProductView`'s constructor from its suppliers list
- The full `suppliers` array (gated by `ProductInclude::Suppliers`) is independent of `default_supplier` and continues to work as before
