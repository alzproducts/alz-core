# Implementation Log: #695 — feat: include general/product settings in entity custom field responses

## Issue Context

Entity custom field endpoints (`GET /products/{id}/custom-fields`, `GET /categories/{id}/custom-fields`, `GET /brands/{id}/custom-fields`) return field values but omit the associated settings (tooltip, validation rules, `admin_only` flag). This is a pure serialization gap — the data already flows through the domain objects to the resource layer. Presentation-layer only change.

## Implementation

### Sub-task 1: `CustomFieldGeneralSettingsResource` (new)
- `app/Presentation/Http/Api/Resources/CustomFieldGeneralSettingsResource.php`
- Constructor accepts `?CustomFieldGeneralSettings`; returns defaults when null: `{tooltip: null, select_type: null, suggest_common_data: null, admin_only: false, field_validation_rule: null}`

### Sub-task 2: `ProductFieldSettingsResource` (new)
- `app/Presentation/Http/Api/Resources/ProductFieldSettingsResource.php`
- Constructor accepts `?ProductFieldSettings`; returns `{stock_item_update_mode: null}` when null, populated value when present

### Sub-task 3: `ConfiguredFieldDefinitionResource` (refactored)
- Removed private `generalBlock()`, `productBlock()`, `productPayload()` methods
- Replaced with inline composition of child resources
- **Behavior change**: product block for product entities with no settings row now returns `{stock_item_update_mode: null}` instead of `null`

### Sub-task 4: `CustomFieldValueResource` (updated)
- Added `general` key: always present via `CustomFieldGeneralSettingsResource`
- Added `product` key: null for non-product entities; defaults object for product entities with no settings row; populated for product entities with a settings row

### Sub-task 5: Tests (updated)
- `CustomFieldValueResourceTest`: added 5 new test cases covering general defaults, populated general, null product (non-product entity), product defaults (no settings row), and populated product block
- `ConfiguredFieldDefinitionResourceTest`: updated `product_block_is_null_when_product_field_has_no_settings_row` → renamed `product_block_returns_defaults_when_product_field_has_no_settings_row` and asserts `['stock_item_update_mode' => null]` instead of null

## Test Results

- `make test-quick` (domain tests): **1643 passed** — all green

## Lint Results

- Pint: passed (no style fixes needed)
- PHPStan: No errors
- PHPArkitect: No violations
- Deptrac: 0 violations
- TLint: LGTM

## Validation

Exercised `GET /api/products/14861606/custom-fields`:
- All fields contain `general` block with all five keys defaulted to null/false
- All product fields contain `product` block
- `range` field returned `"stock_item_update_mode": "single"` — live settings row serialized correctly

## Handoff Notes

- This is an additive, non-breaking change — no existing consumers are broken since new keys are added alongside existing ones
- All success criteria from issue #695 are met
- No shortcuts or technical debt introduced
