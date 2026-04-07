# Implementation Log: Issue #490 — Add RRP to Product Pricing Write Path

## Status: In Progress

## Decisions

- Constructor param `$comparePrice` in `ProductView` stays named as-is (maps to SQL view column)
  → property is renamed to `$rrp` (domain naming)
- `ProductViewMeta` is a self-constructing VO; all `canEditRrp` logic lives inside it
- `array_values(array_unique(['variations', ...]))` in `findProductForApi` ensures variations always loaded
- `ShowProductRequestDTO::allowedIncludes()` filters out `Variations` via array_filter on `ProductInclude::cases()`
- RRP uses zero-means-clear semantics (same as salePrice): `Money::inclusive(0)` clears RRP
- No cross-field validation of RRP vs price/salePrice (user confirmed in plan)

## Changed Files

### Phase 1: Domain Layer
- [x] `app/Domain/Catalog/Product/ValueObjects/ProductRetailPricing.php` — add `?Money $rrp = null`
- [x] `app/Domain/Catalog/Product/Commands/UpdatePriceCommand.php` — add `?Money $rrp`, update `hasAnyUpdate()`
- [x] `app/Domain/Catalog/Product/ValueObjects/ResolvedPriceUpdate.php` — add `resolveRrp()` method
- [x] `app/Domain/Catalog/Product/Validators/PriceChangedValidator.php` — add RRP comparison
- [x] `app/Domain/Catalog/Product/Validators/PriceChangedResult.php` — add `rrp_gross` to context
- [x] `app/Domain/Catalog/Product/Transformers/ProductRetailPricingTransformer.php` — pass `comparePrice` as `rrp`

### Phase 2: Infrastructure
- [x] `app/Infrastructure/Shopwired/Clients/PriceUpdateClient.php` — output `comparePrice` in formatItem

### Phase 3: Presentation Input
- [x] `app/Presentation/Http/Shopwired/DTOs/SkuPriceUpdateDTO.php` — add `rrp` field

### Phase 5: Rename comparePrice → rrp on ProductView + API
- [x] `app/Domain/Catalog/Product/ValueObjects/ProductView.php` — rename property, add meta
- [x] `app/Presentation/Http/Api/Resources/ProductResource.php` — rename API key

### Phase 6: Force variations + ProductViewMeta
- [x] `app/Domain/Catalog/Product/ValueObjects/ProductViewMeta.php` (new)
- [x] `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` — always load variations for detail
- [x] `app/Presentation/Http/Api/DTOs/ShowProductRequestDTO.php` — remove Variations from allowed includes
- [x] `app/Presentation/Http/Api/Resources/ProductDetailResource.php` — always include variations + add meta

### Tests
- [x] `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php` — update `compare_price` → `rrp` in key check

## PR Notes
- Breaking change: `?include=variations` on detail endpoint now returns 422 (coordinated with frontend)
- RRP write path: `POST /products/prices` now accepts `rrp` field
- Product detail response now always includes `variations` and `meta.can_edit_rrp`
