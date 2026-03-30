# Implementation Log — Issue #429

## Issue
Standardise product GET endpoint query infrastructure with catalog views and domain query objects.

## Goal
Introduce domain query objects for the two product GET operations:
- `ProductListQuery` — replaces raw `(int $perPage, int $page, array $includes)`
- `ProductDetailQuery` — replaces raw `(IntId $productId, array $includes)`

## Files Changed

### Created
- `app/Application/Catalog/Queries/ProductListQuery.php`
- `app/Application/Catalog/Queries/ProductDetailQuery.php`

### Modified
- `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` — `paginate()` and `findProductForApi()` now accept query objects
- `app/Application/Catalog/UseCases/ListProductsUseCase.php` — `execute(ProductListQuery)` signature
- `app/Application/Catalog/UseCases/GetProductUseCase.php` — `execute(ProductDetailQuery)` signature
- `app/Application/Catalog/UseCases/GetProductCustomFieldsUseCase.php` — internal: creates `ProductDetailQuery` before calling repo
- `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` — updated both method signatures
- `app/Presentation/Http/Api/Controllers/ProductController.php` — constructs query objects from request DTOs
- `tests/Unit/Application/Catalog/UseCases/ListProductsUseCaseTest.php` — updated for new signature
- `tests/Unit/Application/Catalog/UseCases/GetProductCustomFieldsUseCaseTest.php` — updated mock matchers

## Decisions
- `ProductDetailQuery` takes `IntId $productId` (not raw `int`) — stays consistent with Application layer types
- `GetProductCustomFieldsUseCase::execute()` public signature unchanged — it creates `ProductDetailQuery` internally with hardcoded `['custom_fields']`
- `GetProductResult` unchanged — still carries `array $includes`, which the use case extracts from `ProductDetailQuery`

## Simplify Changes
- Extracted `relationsForIncludes(array $includes): array` private static helper in `EloquentProductRepository` — was duplicated identically in both `paginate()` and `findProductForApi()`
- Aligned docblock terminology: `$includes` now consistently described as "Embed names" in both query param classes
- Updated complexity baseline: class grew from 793 → 805 lines after helper addition

## Status
- [x] Query objects created
- [x] Interface updated
- [x] Use cases updated
- [x] Infrastructure updated
- [x] Controller updated
- [x] Tests passing (1383 passed)
- [x] Lint clean
- [x] Simplify done
- [x] Sweep done (no issues)
