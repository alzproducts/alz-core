# Code Review: #375 Consumer API Write Endpoints

**Date**: 2026-03-27
**Reviewer**: Claude Opus 4.6 (internal-only review)
**Branch**: `feature/375-consumer-api-write-endpoints`
**Scope**: 42 files changed (20 new, 13 modified, 9 deleted)

> **Limitation**: Zen external review tools (codereview, consensus) were unavailable. All findings are internal-only. Confidence levels reduced accordingly.

> **Scale note**: This is a small business application. Recommendations prioritise proportional value.

---

## Executive Summary

This changeset adds consumer API write endpoints for products, categories, and brands (scalar fields + custom fields), migrates existing product write endpoints from `/api/shopwired/` to `/api/` with an approval gate, and extracts shared logic (CustomFieldMergerService, MergesCustomFieldsTrait). The architecture follows established Clean Architecture patterns consistently.

**Overall assessment**: Well-structured, follows project conventions. No critical or high-severity issues. 2 medium and 3 low findings, all straightforward to address.

---

## Findings

### Medium Severity

#### M1. Breaking route changes — verify frontend readiness
**Location**: `routes/api.php:119-173`
**Source**: Internal | **Confidence**: Medium-High

The old `/api/shopwired/` route prefix is removed entirely:
- `POST /api/shopwired/products/free-delivery` -> `POST /api/products/free-delivery`
- `POST /api/shopwired/products/{id}/prices` -> `PUT /api/products/{id}/prices` (method + path)
- `POST /api/products/{id}/custom-fields` -> `PUT /api/products/{id}/custom-fields` (method change)

Additionally, moved endpoints now require `EnsureUserApprovedMiddleware` (approval gate) which was not present on the old `/api/shopwired/` routes. Unapproved users who could previously access price/free-delivery endpoints will now get 403.

The issue description confirms this is intentional ("moved from /api/shopwired/ to /api/ prefix with approval gate"), but the frontend must be updated simultaneously.

**Recommendation**: Confirm frontend client is updated before merging. Consider whether a deprecation period is needed for any external consumers.

---

#### M2. No max length on `description` field in scalar update DTOs
**Location**: `app/Presentation/Http/Api/DTOs/UpdateBrandFieldsRequestDTO.php:41`, `UpdateCategoryFieldsRequestDTO.php:41`, `UpdateProductFieldsRequestDTO.php:41`
**Source**: Internal | **Confidence**: High

All three scalar update DTOs validate `fields.description` as `['string']` with no max length constraint. By comparison:
- `title` / `meta_title`: `max:255`
- `meta_description`: `max:500`
- `description`: **unbounded**

A client could send arbitrarily large HTML payloads. While ShopWired likely enforces its own limits, validating at our API boundary is consistent with project principles ("External data -> Laravel Validator") and prevents unnecessarily large payloads from reaching the upstream API.

**Recommendation**: Add a reasonable max length. ShopWired product descriptions are typically capped around 65,535 characters (MySQL TEXT equivalent). A `max:65535` would be prudent.

---

### Low Severity

#### L1. Stale docblock references old route path
**Location**: `app/Presentation/Http/Shopwired/DTOs/UpdateProductPricesDTO.php:15`
**Source**: Internal | **Confidence**: High

The docblock says `POST /api/shopwired/products/{productId}/prices` but the route is now `PUT /api/products/{productId}/prices`.

**Recommendation**: Update to `PUT /api/products/{productId}/prices`.

---

#### L2. Duplicate match expression in UpdateProductFieldsUseCase
**Location**: `app/Application/Catalog/UseCases/UpdateProductFieldsUseCase.php:67-97`
**Source**: Internal | **Confidence**: High

The `mapFieldUpdates()` method matches string field names (`title`, `description`, `meta_title`, `meta_description`) to dispatch to `mapStringField()`, which then matches the **same names again** to create the correct VO. Adding a new string field requires updating both match expressions.

The brand and category equivalents don't have this issue because they only support string fields (no mixed types).

**Recommendation**: Inline the string assertion into each match arm to eliminate the second match:
```php
'title' => ProductFieldUpdate::title(self::assertAndReturnString($value)),
'description' => ProductFieldUpdate::description(self::assertAndReturnString($value)),
```
Or keep the current structure if the team prefers the explicit type-specific helper methods for readability.

---

#### L3. PHPStan annotation bypass for categories array elements
**Location**: `app/Application/Catalog/UseCases/UpdateProductFieldsUseCase.php:99-104`
**Source**: Internal | **Confidence**: High

The `mapCategoriesField()` method has:
```php
Assert::isArray($value);
/** @var list<int> $value */
return ProductFieldUpdate::categories($value);
```

The `@var` annotation tells PHPStan the array contains integers, but there's no runtime validation of element types. The DTO rules (`'fields.categories.*' => ['integer', 'min:1']`) handle this at the boundary, so this is defence-in-depth only.

**Recommendation**: Consider adding `Assert::allPositiveInteger($value)` for belt-and-suspenders safety, or accept the current approach (DTO handles it).

---

## Positive Findings

1. **Performance optimisation**: `CustomFieldMergerService` uses a `$coveredNames` hash-set for O(n) duplicate detection, improving on the old `array_find()` O(n^2) approach in `GetProductCustomFieldsUseCase`.

2. **Clean interface consolidation**: `BrandFieldUpdateClientInterface` + custom field logic merged into single `BrandUpdateClientInterface`. Same for categories. Reduces interface proliferation while maintaining clean contracts.

3. **Security**: All new write endpoints are behind `ValidateSupabaseJwtMiddleware` + `EnsureUserApprovedMiddleware` + `throttle:api` + `SentryUserContextMiddleware`. No endpoint is accidentally unprotected.

4. **Elegant DI**: The `ShopwiredServiceProvider` uses contextual binding (`when()->needs()->give()`) to parameterise `CustomFieldValueFactory` by entity type. Clean, no service locator patterns.

5. **Reusable traits**: `RejectsUnknownFieldKeysTrait` provides a clean allowlist pattern for DTO validation. `MergesCustomFieldsTrait` shares the fetch-merge-PUT logic across three update clients.

6. **Thorough tests**: `CustomFieldMergerServiceTest` has 7 test cases covering sort order, null handling, extra fields, and empty inputs. Use case tests verify field mapping and error cases.

7. **Clean removal**: No dangling references to deleted `BrandFieldUpdateClientInterface` or `CategoryFieldUpdateClientInterface`. The migration is complete.

---

## Decision Audit Trail

| Finding | Method | Outcome |
|---------|--------|---------|
| M1 Route changes | Read routes/api.php diff, cross-referenced with issue requirements | Confirmed intentional per issue; flagged for frontend coordination |
| M2 Description length | Read all 3 DTOs, compared validation rules across fields | Verified unbounded; other string fields have max constraints |
| L1 Stale docblock | Grep for `api/shopwired` found single remaining reference | Confirmed stale by comparing to current route definition |
| L2 Duplicate match | Read UpdateProductFieldsUseCase, compared with brand/category equivalents | Confirmed product-specific issue due to mixed field types |
| L3 PHPStan annotation | Read mapCategoriesField, verified DTO rules validate element types | Confirmed DTO handles validation; annotation is type-narrowing only |
| Dangling references | Grep for deleted interface names across entire app/ directory | Zero results — clean removal confirmed |
| Security posture | Read route middleware groups in routes/api.php | All write routes in approval-gated group confirmed |
