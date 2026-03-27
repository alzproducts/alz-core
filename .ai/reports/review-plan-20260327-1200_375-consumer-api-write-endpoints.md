# Implementation Plan: Review Fixes for #375

**Review report**: `.ai/reports/review-20260327-1200_375-consumer-api-write-endpoints.md`
**Branch**: `feature/375-consumer-api-write-endpoints`

---

## Accepted Findings (4)

### 1. M2 — Add max length to `description` field in scalar update DTOs
**Severity**: Medium | **File(s)**:
- `app/Presentation/Http/Api/DTOs/UpdateBrandFieldsRequestDTO.php:41`
- `app/Presentation/Http/Api/DTOs/UpdateCategoryFieldsRequestDTO.php:41`
- `app/Presentation/Http/Api/DTOs/UpdateProductFieldsRequestDTO.php:41`

**Fix**: Change `'fields.description' => ['string']` to `'fields.description' => ['string', 'max:65535']` in all three DTOs.

**Why**: All other string fields have max constraints (255/500). Description is unbounded, allowing arbitrarily large payloads to reach ShopWired. Defence at the API boundary.

---

### 2. L1 — Update stale docblock in UpdateProductPricesDTO
**Severity**: Low | **File**: `app/Presentation/Http/Shopwired/DTOs/UpdateProductPricesDTO.php:15`

**Fix**: Change `POST /api/shopwired/products/{productId}/prices` to `PUT /api/products/{productId}/prices`.

**Why**: Docblock references the old removed route. Misleading for developers.

---

### 3. L2 — Simplify duplicate match in UpdateProductFieldsUseCase
**Severity**: Low | **File**: `app/Application/Catalog/UseCases/UpdateProductFieldsUseCase.php:67-97`

**Fix**: Eliminate the `mapStringField()` helper. Inline `Assert::string($value)` + VO creation into each string arm of the outer match:
```php
'title' => ProductFieldUpdate::title(self::assertString($name, $value)),
'description' => ProductFieldUpdate::description(self::assertString($name, $value)),
'meta_title' => ProductFieldUpdate::metaTitle(self::assertString($name, $value)),
'meta_description' => ProductFieldUpdate::metaDescription(self::assertString($name, $value)),
```
With a single `assertString` helper that calls `Assert::string($value)` and returns it. This removes the second match expression entirely.

**Why**: Adding a new string field currently requires updating two match expressions. Single point of change is safer.

---

### 4. L3 — Add runtime assertion for categories array elements
**Severity**: Low | **File**: `app/Application/Catalog/UseCases/UpdateProductFieldsUseCase.php:99-104`

**Fix**: Replace `Assert::isArray($value)` + `@var` annotation with:
```php
Assert::isArray($value);
Assert::allPositiveInteger($value);
```
Remove the `/** @var list<int> $value */` annotation (no longer needed after assertion).

**Why**: Defence-in-depth. The DTO validates element types, but the use case currently trusts a PHPStan annotation for type safety.

---

## Suggested Implementation Order

1. **M2** — DTOs (3 one-line changes, highest impact)
2. **L3** — Assert in mapCategoriesField (1-line addition)
3. **L2** — Simplify match expression (small refactor)
4. **L1** — Docblock fix (trivial)

## Rejected Findings

- **M1** (Breaking route changes) — Skipped. Frontend handles separately.
