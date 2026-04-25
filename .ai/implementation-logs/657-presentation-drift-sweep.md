# Implementation Log: #657 — Presentation drift sweep

## Issue Context
Four Presentation layer bypasses of established response-building patterns:
1. `AbstractCustomFieldValue::toArray()` / `DateTimeCustomFieldValue::toArray()` — wire-format decisions (snake_case, ATOM dates, enum stringification) leaked into Domain
2. `ProductUpdateController::updatePrices()` — inline `buildPriceUpdateResponse()` instead of `Responsable` DTO (inconsistent with sibling `updateCostPrices()`)
3. `ConversationsController` (4 actions) + `ProfileController` — manual `JsonResponse(['data' => ...])` wrapping instead of returning resources directly
4. `ContactFormController::__invoke()` — inline `JsonResponse(['id' => ...])` with no Responsable DTO

## Implementation

### Case 1: CustomFieldValueResource + Domain cleanup

**New file:** `app/Presentation/Http/Api/Resources/CustomFieldValueResource.php`
- `match(true)` for DateTime→ATOM, `default => $field->rawValue()` for all others
- Removes wire-format responsibility from Domain

**Modified:**
- `app/Domain/Catalog/CustomFields/ValueObjects/AbstractCustomFieldValue.php` — removed `toArray()` method
- `app/Domain/Catalog/CustomFields/ValueObjects/DateTimeCustomFieldValue.php` — removed `toArray()` override
- 3 controllers (Brand, Category, Product) — `customFields()` action returns `CustomFieldValueResource::collection($fields)` directly
- 3 detail resources (Brand, Category, Product) — use `CustomFieldValueResource::collection(...)->resolve($request)`
- VO test files: migrated 5 `toArray()` assertions to typed accessors across StringCustomFieldValueTest, DateTimeCustomFieldValueTest, NullCustomFieldValueTest

**New test file:** `tests/Unit/Presentation/Http/Api/Resources/CustomFieldValueResourceTest.php`
- One test per subtype (String, Toggle, DateTime, ValueList, ProductList, Null)
- DateTime ATOM-formatting assertion lives here

### Case 2: PriceUpdateResponseDTO

**New file:** `app/Presentation/Http/Api/Responses/PriceUpdateResponseDTO.php`
- `fromResult(PriceUpdateResult)` factory mirrors `BulkUpdateResponseDTO::fromCostPriceResult()`
- `buildPriceUpdateResponse()` and `mapFailures()` private helpers moved from controller to DTO

**Modified:**
- `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — `updatePrices()` returns `PriceUpdateResponseDTO::fromResult($result)`, private helpers removed

### Case 3: HelpScout response shape

**New file:** `app/Presentation/Http/HelpScout/Resources/AgentProfileResource.php`
- Wraps `SupportAgent`, camelCase keys preserved from existing inline body

**Modified:**
- `app/Presentation/Http/Controllers/HelpScout/ConversationsController.php` — 4 actions return `ConversationResource::collection(...)` directly (return type `ResourceCollection`)
- `app/Presentation/Http/Controllers/HelpScout/ProfileController.php` — returns `new AgentProfileResource($agent)`

### Case 4: ContactSubmissionAcceptedResponseDTO

**New file:** `app/Presentation/Http/Api/Responses/ContactSubmissionAcceptedResponseDTO.php`
- `from(string $submissionId)` named constructor, 200 OK response

**Modified:**
- `app/Presentation/Http/Controllers/ContactForm/ContactFormController.php` — returns `ContactSubmissionAcceptedResponseDTO::from($result->submissionId)`

### Rule file

**New file:** `.claude/rules/domain-value-objects.md`
- Scoped to `app/Domain/**/*ValueObject*.php` and `app/Domain/**/ValueObjects/*.php`
- Prohibits `toArray()`, `toJson()`, or serialisation methods with wire-format decisions

## Test Results
- `make test-quick` (domain only, ~5s): 1632 tests, all passed
- `make test` (full suite): 3263 tests passed, 12 pre-existing deprecation notices (not caused by this PR)

## Lint Results
- Pint: passed (no style changes)
- PHPStan level max: 0 errors
- PHPArkitect: 0 violations
- Deptrac: 0 violations (12278 allowed, 0 skipped)
- TLint: LGTM

All linters clean.

## Handoff Notes
- All 4 drift cases resolved per issue success criteria
- `CustomFieldValueResource` is now the single wire-format authority for custom fields
- Domain VOs have zero serialisation methods — only typed accessors
- `PriceUpdateResponseDTO` and `ContactSubmissionAcceptedResponseDTO` follow the `Responsable` DTO pattern established by `BulkUpdateResponseDTO` / `AsyncRefreshAcceptedResponseDTO`
- `ConversationsController` double-wrap bug corrected (was wrapping `ResourceCollection` which already emits `{"data":[...]}` inside another `{"data":[...]}`)
- `AgentProfileResource` wraps `SupportAgent`; camelCase keys preserved from original inline body
- `.claude/rules/domain-value-objects.md` rule added to prevent future Domain wire-format drift
