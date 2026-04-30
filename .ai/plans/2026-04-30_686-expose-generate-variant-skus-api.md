# Plan: Expose generate-variant-skus as API Endpoint

**Issue:** #686
**Date:** 2026-04-30
**Branch:** `feature/686-generate-variant-skus-api`

## Problem / Goal

The `inventory:generate-variant-skus` artisan command is currently only accessible via the server CLI. The frontend needs to trigger variant SKU generation for a product directly via the consumer API, including passing all command arguments (template SKU and the three boolean flags).

## Success Criteria

- [ ] `POST /api/products/{productId}/generate-variant-skus` is accessible to authenticated, approved users
- [ ] All five command inputs are exposed: `productId` (route param), `template_sku`, `copy_mpn`, `no_supplier`, `is_standard_sign`
- [ ] 200 returned when all variants succeed (or all were already skipped)
- [ ] 207 Multi-Status returned when there are partial failures (some created, some failed)
- [ ] 422 returned when `template_sku` is invalid (bad format or no default supplier)
- [ ] 404 returned when product is not found
- [ ] Existing use case is reused unchanged
- [ ] All linters pass; unit + feature tests added

## Design Decisions

| Decision | Choice | Rationale |
|---|---|---|
| Execution | Synchronous | Caller needs result (which SKUs created, which failed) to take next steps |
| Route | `POST products/{productId}/generate-variant-skus` | Follows existing product-scoped action pattern |
| Controller | `ProductUpdateController::generateVariantSkus()` | Consistent with how other product write/action endpoints are grouped |
| Request DTO | `GenerateVariantSkusRequestDTO` | Spatie LaravelData, matches project pattern |
| SKU validation | Domain VO only | `Sku::fromString()` handles format; no duplication in Presentation |
| Response | `GenerateVariantSkusResponseDTO` (Responsable) | Follows `PriceUpdateResponseDTO` pattern for rich operation results |
| Partial failure status | 207 Multi-Status | Lets caller distinguish success from partial failure by status code |
| `InvalidTemplateException` | Add → 422 in mapper | Template SKU is user input; actionable 422 beats silent 500 |
| `ResourceNotAvailableException` | Keep as 404 (inherits) | No mapper change needed; retry semantics are the same |
| Dry-run | Out of scope | Use case doesn't support it; can be added later |

## High-Level Approach

1. Add `InvalidTemplateException → 422 / ValidationError` to `InternalApiExceptionMapper`
2. Create `GenerateVariantSkusRequestDTO` — `template_sku` (string, required), `copy_mpn`, `no_supplier`, `is_standard_sign` (bools, default false), with `toCommand(int $productId): GenerateVariantSkusCommand`
3. Create `GenerateVariantSkusResponseDTO` implementing `Responsable` — wraps `GenerateVariantSkusResult`, returns 200 or 207 based on `$result->hasFailures()`
4. Add `generateVariantSkus(int $productId, GenerateVariantSkusRequestDTO $data)` to `ProductUpdateController`, inject `GenerateVariantSkusUseCase`
5. Add route `Route::post('products/{productId}/generate-variant-skus', [...])` to the Consumer API group in `routes/api.php`

## Files to Create

- `app/Presentation/Http/Api/DTOs/GenerateVariantSkusRequestDTO.php`
- `app/Presentation/Http/Api/Responses/GenerateVariantSkusResponseDTO.php`
- `tests/Unit/Presentation/Http/Api/DTOs/GenerateVariantSkusRequestDTOTest.php`
- `tests/Unit/Presentation/Http/Api/Responses/GenerateVariantSkusResponseDTOTest.php`
- `tests/Feature/Api/Products/GenerateVariantSkusTest.php`

## Files to Modify

- `app/Presentation/Http/Api/InternalApiExceptionMapper.php` — add `InvalidTemplateException → 422`
- `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — add method + inject use case
- `routes/api.php` — add route

## Response Shape

```json
// 200 OK — all succeeded or all skipped
// 207 Multi-Status — partial failures (created > 0 AND failed > 0)
{
  "product_title": "Custom Sign A3",
  "total": 5,
  "skipped": 2,
  "created": 2,
  "failed": 1,
  "created_variants": [
    "WEB-15430 - Red Large",
    "WEB-15431 - Blue Small"
  ],
  "failed_variation_ids": [8834221]
}
```

## Exception → HTTP Mapping (unchanged for existing exceptions)

| Exception | HTTP Status | Notes |
|---|---|---|
| `InvalidSkuException` | 422 | Already in mapper |
| `InvalidTemplateException` | 422 | **Add to mapper** |
| `ResourceNotFoundException` / `RecordNotFoundException` | 404 | Already in mapper |
| `ResourceNotAvailableException` | 404 | Inherits from `ResourceNotFoundException` |
| `LockAcquisitionException` | 503 | Already in mapper |
| `TransientApiFailure` | 503 | Already in mapper |
| `PermanentApiFailure` | 502 | Already in mapper |
| `DatabaseOperationFailedException` / `DuplicateRecordException` | 500 | Already in mapper via `DomainException` |

## Notes

- The existing `GenerateVariantSkusUseCase` is consumed unchanged — no Application layer work required
- `productId` from the route is an `int` (Laravel route binding) — wrap in `IntId::from()` at the controller boundary
- The controller method should list all `@throws` from the use case's docblock (required by the controller rules)
