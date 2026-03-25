# Implementation Log: #363 — GET /api/products/{productId} with Embeds

## Decisions

- **Namespace refactor**: Moved `ProductModel`, `ProductVariationModel`, `ProductModelMapper` from `Shopwired\Models/Mappers` to `Catalog\Product\Models/Mappers` — models now draw from both ShopWired and Linnworks
- **ProductCostPriceFactory** at `Infrastructure\Catalog\Product\Factories` — lazy-loads ALL cost prices on first access, O(1) lookups, scoped binding for Octane safety
- **ProductVariationModelMapper** — dedicated read-path mapper that enriches variations with Linnworks cost prices; existing `toDomain()` untouched for write path
- **toApiDomain()** on ProductModelMapper — third mapping path alongside `toDomain()` (internal) and `toReadDomain()` (list API); conditionally enriches based on includes
- **GetProductResult** carries includes list — resource layer uses `hasInclude()` to control serialization
- **cost_price added to ProductVariationResource** — always serialized (enriched by mapper when applicable)

## Files Changed

### Moved (git mv)
- `app/Infrastructure/Shopwired/Models/ProductModel.php` → `app/Infrastructure/Catalog/Product/Models/ProductModel.php`
- `app/Infrastructure/Shopwired/Models/ProductVariationModel.php` → `app/Infrastructure/Catalog/Product/Models/ProductVariationModel.php`
- `app/Infrastructure/Shopwired/Mappers/ProductModelMapper.php` → `app/Infrastructure/Catalog/Product/Mappers/ProductModelMapper.php`

### New Files
- `app/Infrastructure/Catalog/Product/Factories/ProductCostPriceFactory.php`
- `app/Infrastructure/Catalog/Product/Mappers/ProductVariationModelMapper.php`
- `app/Application/Catalog/UseCases/GetProductResult.php`
- `app/Application/Catalog/UseCases/GetProductUseCase.php`
- `app/Presentation/Http/Api/DTOs/ShowProductRequestDTO.php`
- `app/Presentation/Http/Api/Resources/ProductDetailResource.php`

### Modified
- `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php` — added `getCostPricesBySku()`
- `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php` — implemented `getCostPricesBySku()`
- `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` — added `findProductForApi()`
- `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` — implemented `findProductForApi()`
- `app/Infrastructure/Catalog/Product/Mappers/ProductModelMapper.php` — added `toApiDomain()`, new deps
- `app/Presentation/Http/Api/Controllers/ProductController.php` — added `show()` method
- `app/Presentation/Http/Api/Resources/ProductVariationResource.php` — added `cost_price` field
- `routes/api.php` — added show route
- `app/Providers/ShopwiredServiceProvider.php` — registered new scoped bindings
- Updated imports in 5 consumers after namespace move

## Simplify Fixes
- Extracted `ProductResource::baseFields()` as shared static method — `ProductDetailResource` delegates instead of duplicating
- Made `ProductVariationModel::buildOptions()` public static — `ProductVariationModelMapper` delegates instead of duplicating
- Added `bool $enrichCostPrice` parameter to `ProductVariationModelMapper::toReadDomain()` — avoids loading entire Linnworks SKU table when only `variations` is requested without `cost_price`
- Gated `rawCustomFields`/`rawFilters` reads in `toApiDomain()` behind includes check — avoids unnecessary JSONB decode
- Inlined `ProductCostPriceFactory::costPrices()` into `getCostPrice()` using `??=`
- Updated `ShopwiredModelMustImplementMappableRule` to also check `Catalog\*\Models\` namespace
- Added exit log to `GetProductUseCase` for consistency with `ListProductsUseCase`

## Testing
- All 2631 existing tests pass (0 failures, 1 skipped)
- All 5 linters pass (Pint, PHPStan max, PHPArkitect, Deptrac, TLint)
- Feature tests for the show endpoint to be added in follow-up commit

## PR Notes
- TBD
