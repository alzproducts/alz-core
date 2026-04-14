# Implementation Log: #555 — Add Links subentity to catalog views with ShopWired admin edit URLs

## Issue Context
ProductView, CategoryView, and BrandView expose a flat `url` string. The goal is to introduce per-entity `Links` VOs grouping the public URL with a ShopWired admin edit URL. CustomerLinks is also created (no public URL) for future use.

## Implementation

### Sub-task 1: Create Links Value Objects (Domain)
- Created `app/Domain/Catalog/Product/ValueObjects/ProductLinks.php`
- Created `app/Domain/Catalog/Category/ValueObjects/CategoryLinks.php`
- Created `app/Domain/Catalog/Brand/ValueObjects/BrandLinks.php`
- Created `app/Domain/Customer/ValueObjects/CustomerLinks.php`

### Sub-task 2: Create ShopwiredAdminUrlResolver (Infrastructure)
- Created `app/Infrastructure/Shopwired/ShopwiredAdminUrlResolver.php`
- Static utility class with 4 pure methods for product/category/brand/customer admin URLs
- Customer URL varies by trade status

### Sub-task 3: Update View VOs (Domain)
- `ProductView`: `public string $url` → `public ProductLinks $links` (constructor-promoted)
- `CategoryView`: `public string $url` → `public CategoryLinks $links`
- `BrandView`: `public string $url` → `public BrandLinks $links`
- Updated `@param` docblocks on all three

### Sub-task 4: Update Mappers/Assemblers (Infrastructure)
- `ProductViewAssembler`: `url: $model->url` → `links: new ProductLinks(...)` + resolver call
- `CategoryModel::toViewDomain()`: same pattern
- `BrandModel::toViewDomain()`: same pattern

### Sub-task 5: Update API Resources (Presentation)
- `ProductResource::baseFields()`: `'url' => ...` → `'links' => [...]`
- `CategoryResource::baseFields()`: same
- `BrandResource::baseFields()`: same

## Test Results

All tests pass: 2996 passed (6905 assertions) — no failures.

## Lint Results

- **Pint**: pass (no style issues)
- **PHPStan**: pass after updating 4 existing complexity baseline entries (line counts shifted +3 per method due to 3-line `links:` blocks in existing over-limit methods)
- **PHPArkitect**: no violations
- **Deptrac**: no violations
- **TLint**: LGTM

Updated entries in `phpstan-complexity-baseline.neon`:
- `ProductViewAssembler.toViewDomain()`: 40 → 43 lines
- `BrandModel.toViewDomain()`: 24 → 27 lines
- `CategoryModel.toViewDomain()`: 28 → 31 lines
- `ProductResource.baseFields()`: 33 → 36 lines

## Handoff Notes

**Breaking API change**: `"url": "..."` in product, category, and brand responses is now `"links": { "public_url": "...", "edit_website_url": "..." }`.

**CustomerLinks** created but not wired to any view yet — ready for a future `CustomerView`.

**Scope respected**: Write models (`Product`, `Category`, `Brand`) retain their flat `$url` string unchanged. Only the read-side (View VOs + assemblers + resources) changed.

**Test files updated**: 6 test files had `url:` in `ProductView`/`CategoryView`/`BrandView` constructor calls — all updated with `links: new XLinks(...)`.

**No concerns** with the implementation. Straightforward Introduce Parameter Object refactor.

