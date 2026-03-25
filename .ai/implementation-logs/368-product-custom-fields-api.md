# Implementation Log: #368 — Product Custom Fields API

## Branch
`feature/368-product-custom-fields-api`

## Decision Log

- **Factory split**: Extracted `CustomFieldValueFactory` (strict, throws on unknown) from `ProductCustomFieldFactory` (graceful, logs and skips). Shared `createTypedValueFromDefinition()` public static method avoids code duplication.
- **Validator inlined**: Originally planned `CustomFieldSubmissionValidator` + `CustomFieldSubmissionResult`, but PHPArkitect requires `*Validator` in `Domain\*\Validators`. Since validator depends on Application contract, inlined the try-catch in the use case instead.
- **DateTimeCustomFieldValue override**: Uses `[...parent::toArray(), 'value' => formatted]` spread to avoid copy-paste divergence if parent gains new fields.
- **`UserInputValidationFailedException` now accepts `previous`**: Added optional `?Throwable $previous` parameter to satisfy ShipMonk's `missingPreviousException` rule.
- **DTO type narrowing**: `UpdateProductCustomFieldsRequestDTO::$custom_fields` typed as `array<string, string|int|bool|null>` to match `ProductUpdateClientInterface::updateCustomFields()`.

## Deviations from Plan

- Validator+Result classes removed — validation inlined in use case (PHPArkitect naming constraint)
- Added `@throws` for infrastructure exceptions to `CustomFieldValueFactoryInterface` (per interface @throws rule)

## Files Changed

### Domain
- `AbstractCustomFieldValue.php` — enriched `toArray()` with `label`, `allowed_values`, `sort_order`
- `DateTimeCustomFieldValue.php` — added `toArray()` override for ATOM format (spread from parent)
- `UserInputValidationFailedException.php` — added optional `?Throwable $previous` parameter

### Application
- `GetProductCustomFieldsUseCase.php` — new, read endpoint use case
- `UpdateProductCustomFieldsUseCase.php` — new, write endpoint with inlined validation
- `CustomFieldValueFactoryInterface.php` — new, strict factory contract

### Infrastructure
- `CustomFieldValueFactory.php` — new, strict factory implementation
- `ProductCustomFieldFactory.php` — refactored to delegate via `createTypedValueFromDefinition()`

### Presentation
- `ProductController.php` — added `customFields()` GET and `updateCustomFields()` POST actions
- `GetProductCustomFieldsRequestDTO.php` — new, validates `?fields[]` query param
- `UpdateProductCustomFieldsRequestDTO.php` — new, validates `custom_fields` body
- `routes/api.php` — added GET/POST product custom-fields routes

### Provider
- `ShopwiredServiceProvider.php` — registered `CustomFieldValueFactoryInterface` binding

## Test Results
- 2691 passing, 0 failing, 1 pre-existing skip

## Lint Results
- All 5 linters pass (Pint, PHPStan, PHPArkitect, Deptrac, TLint)

## Simplify Findings (noted for later)
- `registry()` duplicated between both factories — separate request paths, low priority
- `GetProductCustomFieldsUseCase` loads full product for custom fields — would need new repo method
- `validateFields()` discards typed values — by design, update client needs raw values

## PR Notes
- Enriched `toArray()` on custom field value objects (additive, non-breaking for existing `?include=custom_fields`)
- New `GET /api/products/{id}/custom-fields` with optional `?fields[]` filter
- New `POST /api/products/{id}/custom-fields` with strict validation against registry
- Factory extraction enables strict vs graceful validation modes
- `UserInputValidationFailedException` now supports exception chaining via optional `previous`
