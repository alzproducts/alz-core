# Implementation Log: #688 — Add description2 to Category scalar update endpoint

## Issue Context
The `description2` field on ShopWired categories is already stored, synced, and returned via the API, but cannot be updated via `PUT /api/categories/{categoryId}`. It should be editable in the same way `description` is today.

Success Criteria:
- `PUT /api/categories/{categoryId}` accepts `fields.description2`
- Unknown field rejection still works (existing behaviour unchanged)
- ShopWired receives `description2` in the PUT payload
- Use case test covers the `description2` mapping

## Implementation

### Files Changed

1. **`app/Domain/Catalog/Category/Enums/CategoryUpdatableField.php`** — Added `Description2` enum case
2. **`app/Domain/Catalog/Category/ValueObjects/CategoryFieldUpdate.php`** — Added `description2()` static factory method
3. **`app/Infrastructure/Shopwired/Clients/CategoryUpdateClient.php`** — Added `Description2 => 'description2'` to `mapField()` match
4. **`app/Application/Catalog/UseCases/UpdateCategoryFieldsUseCase.php`** — Added `'description2'` case to `buildFieldUpdates()` match
5. **`app/Presentation/Http/Api/DTOs/UpdateCategoryFieldsRequestDTO.php`** — Added `fields.description2` validation rule and `description2` to `allowedFieldKeys()`
6. **`tests/Unit/Application/Catalog/UseCases/UpdateCategoryFieldsUseCaseTest.php`** — Updated `maps_all_four_fields_and_delegates_to_client` → `maps_all_five_fields_and_delegates_to_client` to include `description2`

### Approach
Followed the exact same pattern as `description`. Each layer received the minimum change needed: enum case, factory method, infrastructure mapping, use case match, DTO validation, and test coverage.

## Test Results

- **3321 passed** (7548 assertions), 12 pre-existing notices (unrelated to this change)
- All existing tests pass; updated `maps_all_five_fields_and_delegates_to_client` covers `description2`

## Lint Results

- **Pint**: passed (no style fixes needed)
- **PHPStan**: no errors
- **PHPArkitect**: no violations
- **Deptrac**: 0 violations, 0 uncovered
- **TLint**: LGTM

## Handoff Notes

- Validation was not performed live because the endpoint is write-only and would mutate ShopWired production data. All paths are covered by the unit test.
- No new files created — purely additive changes to existing files.
- All success criteria from the issue are met: DTO accepts `fields.description2`, unknown field rejection unchanged, ShopWired receives `description2` key in PUT payload, use case test covers the mapping.
