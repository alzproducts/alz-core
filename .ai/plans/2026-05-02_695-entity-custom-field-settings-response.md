# Plan: Include general/product settings in entity custom field responses

**Date:** 2026-05-02
**Issue:** #695
**Scope:** Presentation layer only — no domain, application, or infrastructure changes.

## Context

The entity custom field endpoints (`GET /products/{id}/custom-fields`, `GET /categories/{id}/custom-fields`, `GET /brands/{id}/custom-fields`) return field values via `CustomFieldValueResource`. The frontend needs the settings from `custom_field_general_settings` and `custom_field_product_settings` to render tooltips, apply validation rules, and hide admin-only fields.

The data already flows through to `CustomFieldValueResource` — `AbstractCustomFieldValue` embeds a `ConfiguredFieldDefinition` which already holds `generalSettings` and `productSettings` (eager-loaded by the repository). This is purely a serialization gap.

## Design Decisions (from grill-me session)

1. **Flat response shape** — `general` and `product` keys added directly alongside existing fields (non-breaking, additive). The issue's proposed shape is correct.
2. **Child resources for settings** — create `CustomFieldGeneralSettingsResource` and `ProductFieldSettingsResource` as dedicated `JsonResource` classes. Both parent resources (`CustomFieldValueResource` and `ConfiguredFieldDefinitionResource`) compose them.
3. **Refactor `ConfiguredFieldDefinitionResource`** — replace its inline `generalBlock()`/`productBlock()` methods with the new child resources for consistency.
4. **`general` block always present** — when no settings row exists, defaults to `{tooltip: null, select_type: null, suggest_common_data: null, admin_only: false, field_validation_rule: null}`. The resource handles null internally.
5. **`product` block** — `null` for non-product entities (parent guards with `isProductField()`). For product entities: always returns an object (defaults to `{stock_item_update_mode: null}` when no settings row), matching the `general` pattern. This supersedes the current `ConfiguredFieldDefinitionResource` behavior of returning `null` for product fields with no settings row.
6. **`admin_only` filtering deferred** — all fields returned regardless of `admin_only` flag; frontend handles visibility. A separate issue should scope role-based filtering once auth requirements are defined.

## Response Shape

```json
{
  "name": "material",
  "type": "text",
  "label": "Material",
  "value": "cotton",
  "allowed_values": ["cotton", "polyester"],
  "sort_order": 5,
  "general": {
    "tooltip": "Enter the primary material",
    "select_type": null,
    "suggest_common_data": true,
    "admin_only": false,
    "field_validation_rule": null
  },
  "product": {
    "stock_item_update_mode": "all_variants"
  }
}
```

For non-product entities: `"product": null`.
For product entities with no settings row: `"product": {"stock_item_update_mode": null}`.

## Files to Change

### 1. `app/Presentation/Http/Api/Resources/CustomFieldGeneralSettingsResource.php` *(new)*
- `final class`, `@mixin CustomFieldGeneralSettings`
- Constructor accepts `?CustomFieldGeneralSettings`
- When null: return defaults `{tooltip: null, select_type: null, suggest_common_data: null, admin_only: false, field_validation_rule: null}`
- When populated: map properties with enum `->value` stringification

### 2. `app/Presentation/Http/Api/Resources/ProductFieldSettingsResource.php` *(new)*
- `final class`, `@mixin ProductFieldSettings`
- Constructor accepts `?ProductFieldSettings`
- When null: return defaults `{stock_item_update_mode: null}`
- When populated: `stock_item_update_mode => $settings->stockItemUpdateMode?->value`

### 3. `app/Presentation/Http/Api/Resources/ConfiguredFieldDefinitionResource.php` *(refactor)*
- Replace `generalBlock()` method body with `(new CustomFieldGeneralSettingsResource($definition->generalSettings))->toArray($request)`
- Replace `productBlock()` method: non-product → `null`; product → `(new ProductFieldSettingsResource($definition->productSettings))->toArray($request)` (no longer returns null for missing row)
- Remove `productPayload()` private method

### 4. `app/Presentation/Http/Api/Resources/CustomFieldValueResource.php` *(update)*
- Add two keys to `toArray()`:
  ```php
  'general' => (new CustomFieldGeneralSettingsResource($value->definition->generalSettings))->toArray($request),
  'product' => $value->definition->base->isProductField()
      ? (new ProductFieldSettingsResource($value->definition->productSettings))->toArray($request)
      : null,
  ```

### 5. Tests *(update)*
- `tests/Unit/Presentation/Http/Api/Resources/CustomFieldValueResourceTest.php` — assert `general` and `product` keys present in response; assert defaults when settings null; assert null for non-product entity
- `tests/Unit/Presentation/Http/Api/Resources/ConfiguredFieldDefinitionResourceTest.php` — assert product block now returns defaults object instead of null for product field with no settings row

## Out of Scope

- Domain layer — no changes to `AbstractCustomFieldValue`, `ConfiguredFieldDefinition`, or settings VOs
- Application layer — no changes to use cases or `CustomFieldMergerService`
- Infrastructure layer — no changes to repository or eager-loading (already loads settings relations)
- `admin_only` filtering — deferred to separate issue
- Consumer API auth/role coupling — not part of this change
