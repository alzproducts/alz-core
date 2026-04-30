# Plan: Add description2 to BrandView API

**Date:** 2026-04-30
**Scope:** Read path only — no write-side changes.

## Context

Brands in ShopWired have a custom field named `description2`. We want to surface this field through the `BrandView` domain value object and return it in the consumer API's brand detail endpoint.

This differs from `CategoryView.description2` which is a native DB column — for brands, the value lives inside the `custom_fields` JSON column and must be promoted to a first-class property by the assembler.

## Design Decisions (from grill-me session)

1. **`description2` is a first-class `?string` property on `BrandView`** — the API consumer should not care about storage origin. The assembler promotes it from `custom_fields['description2']`.
2. **Piggybacks on `BrandInclude::Description`** — no new enum case. When `?include=description` is requested, both `description` and `description2` are populated.
3. **Null when missing** — if the brand has no `description2` custom field (or it is empty), the property is `null`.
4. **Filtered from `custom_fields` array** — to avoid duplication, the assembler removes `description2` from the raw fields array before passing to `CustomFieldFactory`.
5. **Filter location: `BrandViewAssembler`** — not inside `CustomFieldFactory`, keeping the concern local to the assembler.

## Files to Change

### 1. `app/Domain/Catalog/Brand/ValueObjects/BrandView.php`
- Add `public ?string $description2 = null` property after `$description` and before `$customFields`
- Add corresponding `@param` docblock entry

### 2. `app/Infrastructure/Catalog/Brand/Mappers/BrandViewAssembler.php`
- In `toViewDomain()`: populate `description2` from `$model->custom_fields['description2'] ?? null` when `BrandInclude::Description` is in `$includes`
- In `resolveCustomFields()`: filter `description2` out of the raw fields array before passing to `CustomFieldFactory::fromRawFields()`

### 3. `app/Presentation/Http/Api/Resources/BrandDetailResource.php`
- Inside the `BrandInclude::Description` block, additionally emit `'description2' => $brand->description2`

### 4. Tests
- `tests/Unit/Infrastructure/Catalog/Brand/Mappers/BrandViewAssemblerTest.php` — assert `description2` is populated from custom fields when Description include is present, null otherwise, and excluded from the custom_fields collection
- `tests/Unit/Presentation/Http/Api/Resources/BrandDetailResourceTest.php` — assert `description2` appears in response when Description is included

## Out of Scope
- Write path (BrandUpdatableField, BrandFieldUpdate, BrandUpdateClient, UpdateBrandFieldsUseCase, DTO) — no changes
- No new `BrandInclude` enum case
