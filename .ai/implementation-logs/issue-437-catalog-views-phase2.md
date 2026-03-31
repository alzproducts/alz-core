# Implementation Log: Issue #437 Phase 2 — Filters, VO Refactoring, Assembler Cleanup

## Context
Follows the initial #437 implementation (views + query infrastructure). This phase completes the API surface: wires all filters, refactors VOs to self-construct from primitives, removes raw custom field access from the assembler, and adds `has_free_delivery` + `effective_price` fields.

## Decision Log

| Decision | Rationale |
|----------|-----------|
| VO self-construction from primitives | Constructor signature documents the SQL view's row shape; assembler becomes thinner |
| `has_free_delivery` as computed SQL column | Enables DB-level filtering without JSONB access in WHERE clause |
| GIN index on `category_ids` | `whereJsonContains` performs full table scan without it |
| `buildFilters()` extracted from `toQuery()` | Keeps filter construction logic isolated and testable |
| `findCustomFieldByName()` helper | Reusable typed CF lookup; replaces direct raw JSONB access in assembler |
| `isSaleActive()`/`retailMargin()` removed from ProductView | Zero production callers — write-path uses `Product::isSaleActive()`, margin computed in SQL view |

## Files Changed

### Database
- `database/migrations/2026_03_31_110001_create_catalog_product_views.php` — added `has_free_delivery` computed column
- `database/migrations/2026_03_31_120000_add_category_ids_gin_index_shopwired_products.php` — **NEW** GIN index

### Domain
- `app/Domain/Catalog/Product/ValueObjects/ProductView.php` — primitive constructor, self-construction, `effectivePrice`, `hasFreeDelivery`, removed `isSaleActive`/`retailMargin`
- `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php` — primitive constructor, self-construction, `effectivePrice`
- `app/Domain/Catalog/Product/Enums/ProductFilterField.php` — added `HasFreeDelivery`

### Infrastructure
- `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` — typed CF pipeline, `findCustomFieldByName()`, pass primitives
- `app/Infrastructure/Catalog/Product/Mappers/ProductVariationModelMapper.php` — pass primitives, removed unused imports
- `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php` — `has_free_delivery` property + cast
- `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` — `HasFreeDelivery` in `buildScope()`

### Presentation
- `app/Presentation/Http/Api/DTOs/ListProductsRequestDTO.php` — 4 filter params, `buildFilters()`
- `app/Presentation/Http/Api/Resources/ProductResource.php` — `effective_price`, `has_free_delivery`
- `app/Presentation/Http/Api/Resources/ProductVariationResource.php` — `effective_price`

### Tests
- `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductViewTest.php` — primitive helpers, `hasFreeDelivery` tests, self-construction tests, removed `isSaleActive`/`retailMargin` tests
- `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductVariationViewTest.php` — primitive helpers, `effectivePrice`/`sku` tests
- `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php` — filter pass-through tests, validation tests, primitive `createProduct` helper

### Config
- `phpstan-complexity-baseline.neon` — updated method/class length entries for changed files

## PR Notes
Phase 2 of #437: completes the product list API by wiring all filter params (category_id, is_on_sale, sku, has_free_delivery), refactoring VOs to self-construct from primitives, removing raw custom field access from the assembler, and adding effective_price + has_free_delivery to the API response.
