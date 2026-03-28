# Implementation Log: #375 Consumer API Write Endpoints

## Branch: `feature/375-consumer-api-write-endpoints`

## Decision Log

- Following plan at `.ai/plans/2026-03-27_375-consumer-api-write-endpoints-products-categories-brands.md`
- Parameterising `CustomFieldValueFactory` with `CustomFieldItemType` via contextual bindings
- Renamed `CustomFieldMerger` to `CustomFieldMergerService` to satisfy PHPArkitect naming rules
- Moving `ProductUpdateController` from `Shopwired/` namespace to `Api/Controllers/`
- All scalar field update use cases follow same pattern: map field names -> VOs -> client call
- Product field updates use `Assert` for type narrowing (PHPStan forbids raw casts on `mixed`)
- Used `default => throw new LogicException(...)` in match arms to satisfy PHPStan exhaustiveness
- Extracted `MergesCustomFieldsTrait` for DRY custom field merge across 3 infra update clients
- Fixed O(n*m) orphan lookup in `CustomFieldMergerService` with `$coveredNames` hash set

## Deviations from Plan

- `CustomFieldMerger` renamed to `CustomFieldMergerService` (PHPArkitect enforced naming)
- `CustomFieldValueFactory` scoped binding replaced with 3 contextual bindings (per use case consumer)
- Product update use case needed `mapFieldUpdates()` private method for PHPStan-safe type narrowing
- Added `MergesCustomFieldsTrait` (not in plan) — eliminated triplication across update clients

## Completed Phases

### Phase 1: Domain
- [x] Expand `CategoryUpdatableField` enum (+3 cases)
- [x] Expand `BrandUpdatableField` enum (+3 cases)
- [x] Expand `CategoryFieldUpdate` VO (+3 factories)
- [x] Expand `BrandFieldUpdate` VO (+3 factories)

### Phase 2: Infrastructure
- [x] Parameterise `CustomFieldValueFactory` with `CustomFieldItemType`
- [x] Expand `CategoryFieldUpdateClient::mapField()`
- [x] Expand `BrandFieldUpdateClient::mapField()`
- [x] Create `CategoryUpdateClientInterface`
- [x] Create `BrandUpdateClientInterface`
- [x] Create `CategoryUpdateClient` (fetch-merge-PUT)
- [x] Create `BrandUpdateClient` (fetch-merge-PUT)
- [x] Update `ShopwiredServiceProvider` bindings
- [x] Extract `MergesCustomFieldsTrait` (simplify pass)

### Phase 3: Application
- [x] Extract `CustomFieldMergerService` (renamed from `CustomFieldMerger`)
- [x] Create `UpdateProductFieldsUseCase`
- [x] Create `UpdateCategoryFieldsUseCase`
- [x] Create `UpdateBrandFieldsUseCase`
- [x] Create `UpdateCategoryCustomFieldsUseCase`
- [x] Create `UpdateBrandCustomFieldsUseCase`
- [x] Create `GetCategoryCustomFieldsUseCase`
- [x] Create `GetBrandCustomFieldsUseCase`

### Phase 4: Presentation
- [x] Create request DTOs (7 new files)
- [x] Create `ProductUpdateController` (new location in Api/)
- [x] Create `CategoryUpdateController`
- [x] Create `BrandUpdateController`
- [x] Extend `CategoryController` with `customFields()`
- [x] Extend `BrandController` with `customFields()`
- [x] Update `routes/api.php`
- [x] Delete old `ProductUpdateController`

### Validation
- [x] Tests: 2739 pass (fixed factory test + added 2 field mapping tests)
- [x] Lint: All 5 linters pass (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
- [x] Simplify: 3 issues fixed (trait extraction, O(n*m) fix, @throws cleanup)
- [x] Sweep: 1 issue fixed (added field mapping tests for new enum cases)

## Further Work (Phase 5)
- [ ] Unit tests for 7 new use cases
- [ ] Unit tests for `CustomFieldMergerService`
- [ ] Integration tests for `CategoryUpdateClient` and `BrandUpdateClient`

## PR Notes

### What
Consumer API write endpoints for products, categories, and brands:
- `PUT /api/{entity}/{id}` for scalar field updates
- `GET /api/{entity}/{id}/custom-fields` for enriched custom field reads (categories + brands)
- `POST /api/{entity}/{id}/custom-fields` for custom field writes (categories + brands)
- Migrated product write endpoints from `/api/shopwired/` to `/api/` prefix

### Why
Frontend needs write capabilities across all catalog entities, not just products.

### Key Decisions
- Parameterised `CustomFieldValueFactory` with `CustomFieldItemType` (reusable validation for all entity types)
- Extracted `CustomFieldMergerService` for DRY merge logic across product/category/brand custom field reads
- Extracted `MergesCustomFieldsTrait` for DRY custom field merge across infra update clients
- Moved product write endpoints to consumer API group (approval gate + cleaner URL structure)

### Testing
- All 2739 tests pass (2737 existing + 2 new field mapping tests)
- All 5 linters pass at max strictness
