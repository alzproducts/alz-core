# Implementation Log: #369 Consumer API Expansion

## Scope
Catalog endpoints only (Categories, Brands, Filter Groups) — Orders and Customers deferred to separate PRs.

## Decisions

- **VO move first**: Moved Category/Brand VOs from `Domain/Catalog/ValueObjects/` to `Domain/Catalog/Category/ValueObjects/` and `Domain/Catalog/Brand/ValueObjects/` for subdirectory organization
- **28 import updates** across 25+ files for the namespace move
- **CategoryView/BrandView**: New domain-typed View VOs with `IntId` instead of raw `int`, conditional includes (null = not loaded)
- **FilterGroups**: Reuse `FilterGroupDefinition` directly (only 4 fields, no View VO needed), no show endpoint
- **include_inactive**: List endpoints support `include_inactive=true` parameter; show endpoints return any entity regardless of active status
- **No list includes**: List endpoints for categories and brands have no includes initially (simple enough without them)
- **Show includes**: Categories: description, description2, parent_ids, custom_fields. Brands: description, custom_fields

## Files Created
- `app/Domain/Catalog/Category/ValueObjects/CategoryView.php`
- `app/Domain/Catalog/Brand/ValueObjects/BrandView.php`
- `app/Application/Catalog/UseCases/ListCategoriesUseCase.php`
- `app/Application/Catalog/UseCases/GetCategoryUseCase.php`
- `app/Application/Catalog/UseCases/GetCategoryResult.php`
- `app/Application/Catalog/UseCases/ListBrandsUseCase.php`
- `app/Application/Catalog/UseCases/GetBrandUseCase.php`
- `app/Application/Catalog/UseCases/GetBrandResult.php`
- `app/Application/Catalog/UseCases/ListFilterGroupsUseCase.php`
- `app/Presentation/Http/Api/Controllers/CategoryController.php`
- `app/Presentation/Http/Api/Controllers/BrandController.php`
- `app/Presentation/Http/Api/Controllers/FilterGroupController.php`
- `app/Presentation/Http/Api/Resources/CategoryResource.php`
- `app/Presentation/Http/Api/Resources/CategoryDetailResource.php`
- `app/Presentation/Http/Api/Resources/BrandResource.php`
- `app/Presentation/Http/Api/Resources/BrandDetailResource.php`
- `app/Presentation/Http/Api/Resources/FilterGroupResource.php`
- `app/Presentation/Http/Api/DTOs/ListCategoriesRequestDTO.php`
- `app/Presentation/Http/Api/DTOs/ShowCategoryRequestDTO.php`
- `app/Presentation/Http/Api/DTOs/ListBrandsRequestDTO.php`
- `app/Presentation/Http/Api/DTOs/ShowBrandRequestDTO.php`
- `app/Presentation/Http/Api/DTOs/ListFilterGroupsRequestDTO.php`

## Files Modified
- `app/Application/Contracts/Shopwired/CategoryRepositoryInterface.php` — added `paginate()`, `findCategoryForApi()`
- `app/Application/Contracts/Shopwired/BrandRepositoryInterface.php` — added `paginate()`, `findBrandForApi()`
- `app/Application/Contracts/Shopwired/FilterGroupRepositoryInterface.php` — added `paginate()`
- `app/Infrastructure/Shopwired/Repositories/EloquentCategoryRepository.php` — implemented `paginate()`, `findCategoryForApi()`
- `app/Infrastructure/Shopwired/Repositories/EloquentBrandRepository.php` — implemented `paginate()`, `findBrandForApi()`
- `app/Infrastructure/Shopwired/Repositories/EloquentFilterGroupRepository.php` — implemented `paginate()`
- `app/Infrastructure/Shopwired/Models/CategoryModel.php` — added `toViewDomain()`
- `app/Infrastructure/Shopwired/Models/BrandModel.php` — added `toViewDomain()`
- `routes/api.php` — added category, brand, filter-group routes
- `app/Domain/CLAUDE.md` — added Native Domain Types reference
- 25+ files — updated imports for VO namespace move

## Simplify Findings
- Removed dead `include` parameter + `ValidatesIncludesTrait` from `ListCategoriesRequestDTO` and `ListBrandsRequestDTO` (list endpoints don't support includes)
- Updated controllers to stop passing `includes` to list use cases
- Skipped: BrandImage/CategoryImage consolidation (pre-existing, out of scope), GetResult abstraction (premature), logging pattern (matches existing codebase)

## Tests
- All 2691 tests pass, 1 pre-existing skip

## Lint
- All 5 linters pass: Pint, PHPStan, PHPArkitect, Deptrac, TLint

## PR Notes
TBD
