# Implementation Log: #572 — fix: decouple variation eager-loading from API exposure in catalog product views

## Issue Context
`defaultSupplier` is null on the ProductList endpoint when `?include=variations` is not requested. Products without a direct Linnworks stock item derive their default supplier from variations, but variations are not eager-loaded unless explicitly included. The same coupling causes `hasAnySale` and `ProductViewMeta` flags (`canEditRrp`, `canEditCostPrice`) to compute incorrectly when variations are absent.

**Root cause**: "what to load from DB" and "what to expose in API" are coupled — both gated by the `includes` array.

**Fix**: Decouple them. Always eager-load variations for internal derivation; only expose them in the API response when the client requests `?include=variations`.

## Implementation

### Sub-task 1: Always eager-load variation relations

**File**: `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

- Removed both `if ($has(ProductInclude::Variations))` conditionals from `relationsForIncludes()`
- Moved `variations`, `variations.extraData`, `variations.stockItem.suppliers` to always-loaded block
- Added comment explaining the decoupling intent
- Simplified `findProductView()` (line ~139): removed redundant `array_unique` prepend since `relationsForIncludes()` now always includes those relations

### Sub-task 2: Accept pre-computed meta/sale flag in ProductView

**File**: `app/Domain/Catalog/Product/ValueObjects/ProductView.php`

- Added `ProductViewMeta $meta` as required constructor param (after `updatedAt`, before optional params)
- Added `bool $hasAnyVariationOnSale = false` as optional constructor param
- Replaced `$this->meta = new ProductViewMeta($variations, $defaultSupplier, $isComposite)` with `$this->meta = $meta`
- Replaced `$this->hasAnySale = $this->isOnSale || self::anyVariationOnSale($this->variations)` with `$this->hasAnySale = $this->isOnSale || $hasAnyVariationOnSale`
- Changed `anyVariationOnSale()` from `private static` to `public static` so the assembler can call it

### Sub-task 3: Gate API exposure in assembler

**File**: `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`

- Added `use App\Domain\Catalog\Product\ValueObjects\ProductViewMeta`
- Renamed `$variations` → `$allVariations` (always available from always-loaded relation)
- Extracted `$defaultSupplier` variable before constructor call (was inline before)
- Changed `variations:` arg to `\in_array(ProductInclude::Variations, $includes, true) ? $allVariations : null` — API gating
- Added `meta: new ProductViewMeta($allVariations, $defaultSupplier, $stockItem?->is_composite)` — always computed from full list
- Added `hasAnyVariationOnSale: ProductView::anyVariationOnSale($allVariations)` — always computed from full list

### Sub-task 4: Update tests

**Files**: `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductViewTest.php`, `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php`

- Added `ProductViewMeta` import to both test files
- Updated `createView()` helper in `ProductViewTest` to pass `meta` and `hasAnyVariationOnSale` derived from the `$variations` param
- Updated both `new ProductView()` calls in `ProductControllerTest` with same new params

### Sub-task 5: Update complexity baseline

**File**: `phpstan-complexity-baseline.neon`

- Updated `ProductView::__construct()` entry: 54 → 56 lines
- Updated `ProductViewAssembler::toViewDomain()` entry: 45 → 48 lines
- Updated `EloquentProductRepository` class entry: 897 → 893 lines
  (class shrank by 4 lines from removing the array_unique prepend)

## Test Results
- 3005 tests passed, 6900 assertions
- Duration: 14.83s (10 parallel processes)
- No failures

## Lint Results
- Pint: pass (no style changes needed)
- PHPStan: pass after updating 3 complexity baseline entries (line count shifts only — per CLAUDE.md policy)
- PHPArkitect: no violations
- Deptrac: no violations
- TLint: LGTM

## Handoff Notes
- All success criteria from the issue are met:
  - `default_supplier` now always computed from variation data regardless of include
  - `has_any_sale` reflects variation sale status even on list endpoint
  - `meta.can_edit_rrp` and `meta.can_edit_cost_price` always computed from real variation data
  - `variations` key is absent from list response unless `?include=variations` is requested
- No new baseline entries were created — only line count updates to existing entries
- The `findProductView()` simplification was possible because `relationsForIncludes()` now always covers what was being prepended manually
- Performance impact is ~4 additional `whereIn` queries per list page (not per product) — expected and acceptable per the plan
