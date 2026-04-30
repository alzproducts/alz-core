# Implementation Log: #686 — Expose generate-variant-skus as API endpoint

**Branch:** `feature/686-generate-variant-skus-api`
**Plan:** `.ai/plans/2026-04-30_686-expose-generate-variant-skus-api.md`
**Status:** In Progress
**Started:** 2026-04-30

## Decision Log

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | `InvalidTemplateException` added to all three mapper match arms (status, type, errors) | Consistent with `ProductSettingsNotApplicableException` pattern — surfaces `context()` as validation errors |
| 2 | Request DTO uses `toCommand(int $productId)` — takes route param as argument | Matches `CostPriceItemDTO::toCommand()` pattern; value object construction (`IntId`, `Sku`) happens inside |
| 3 | Response DTO uses private `statusCode()` method for 200 vs 207 logic | Only returns 207 when `created > 0 AND failed > 0` — all-skipped/no-variations is 200 |
| 4 | Controller method placed last in `ProductUpdateController` | Follows existing order convention — reads first, then writes/actions |

## Deviations from Plan

None — implementation matches the plan exactly.

## Files Created

- `app/Presentation/Http/Api/DTOs/GenerateVariantSkusRequestDTO.php`
- `app/Presentation/Http/Api/Responses/GenerateVariantSkusResponseDTO.php`

## Files Modified

- `app/Presentation/Http/Api/InternalApiExceptionMapper.php` — added `InvalidTemplateException` → 422/ValidationError
- `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — added `generateVariantSkus()` + injected use case
- `routes/api.php` — added `POST products/{productId}/generate-variant-skus`

## Test Results

- 3321 existing tests pass, 0 failures, 12 pre-existing notices (unrelated)

## Lint Results

- Pint: pass (auto-fixed import ordering in mapper)
- PHPStan level max: pass
- PHPArkitect: pass (0 violations)
- Deptrac: pass (0 violations)
- TLint: pass

## Validation

- Route registered correctly (`php artisan route:list`)
- 401 on unauthenticated requests (middleware working)
- 422 on missing `template_sku` (Spatie Data validation working)
- 422 on empty `template_sku` (Required attribute working)
- 404 on non-numeric `productId` (whereNumber constraint working)
- Happy path not exercised (write-focused endpoint — would mutate external systems)

## Simplify/Sweep Review Findings

- 6 findings across 3 review agents — all evaluated and skipped:
  - Response DTO 200 on total failure: matches plan spec (207 = partial, not total)
  - `InvalidSkuException` missing from `validationErrors()`: pre-existing gap, out of scope
  - Double `refreshById` in use case: out of scope (use case unchanged per plan)
  - `\count()` repetition in use case: out of scope
  - `fromResult` indirection: matches project pattern
  - `no_supplier` double-negative naming: inherited from artisan command

## PR Notes

### What
Expose the existing `inventory:generate-variant-skus` artisan command as a consumer API endpoint.

### Why
Frontend needs to trigger variant SKU generation for a product directly via the consumer API, passing template SKU and boolean flags as request body fields.

### Key Decisions
- `InvalidTemplateException` → 422/ValidationError in exception mapper (surfaces `context()` with template_sku and reason)
- 207 Multi-Status only on partial failures (created > 0 AND failed > 0); total failures and all-skipped return 200
- Use case consumed unchanged — all new code is Presentation layer only

### Testing
- All 3321 existing tests pass
- All 5 linters pass clean
- API validation verified via curl: auth, required fields, type constraints
