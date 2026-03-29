# Implementation Log: #393 — Add PHP code size complexity rules to PHPStan

## Issue Context

The PHP backend lacked enforcement of code size metrics (method length, class length, constructor dependency count) that the React frontend already enforces via ESLint. PHPMD was the natural choice but is PHP 8.4 incompatible (property hooks break the parser). Custom PHPStan rules were used instead.

## Implementation

### Part 1: Config changes

**`phpstan.neon`**
- Added `dependency_tree: 10` to the `cognitive_complexity` block — limits constructor dependencies per class to 10

**`phpstan-custom-rules.neon`**
- Added Batch 7 with 3 rules:
  - `Symplify\PHPStanRules\Rules\Complexity\ForeachCeptionRule` (package already installed, registered individually to avoid overlap with cognitive-complexity)
  - `App\DevTools\PHPStan\Rules\Complexity\ExcessiveMethodLengthRule`
  - `App\DevTools\PHPStan\Rules\Complexity\ExcessiveClassLengthRule`

### Part 2: New custom rules

**`app/DevTools/PHPStan/Rules/Complexity/ExcessiveMethodLengthRule.php`**
- Node: `ClassMethod`
- Threshold: 50 lines (`endLine - startLine`)
- Scope: `App\` namespace only
- Identifier: `alz.excessiveMethodLength`

**`app/DevTools/PHPStan/Rules/Complexity/ExcessiveClassLengthRule.php`**
- Node: `Class_`
- Threshold: 300 lines (`endLine - startLine`)
- Scope: `App\` namespace only, excludes `Database\Migrations` namespace
- Identifier: `alz.excessiveClassLength`

**`app/DevTools/PHPStan/Rules/Complexity/ExcessiveParameterCountRule.php`**
- Node: `ClassMethod`
- Threshold: 4 parameters (constructors excluded)
- Scope: `App\` namespace only
- Identifier: `alz.excessiveParameterCount`

### Part 3: Tests

Added `DevTools` testsuite to `phpunit.xml`.

Created test files + fixtures:
- `tests/Unit/DevTools/PHPStan/Rules/Complexity/ExcessiveMethodLengthRuleTest.php`
- `tests/Unit/DevTools/PHPStan/Rules/Complexity/ExcessiveClassLengthRuleTest.php`
- `tests/Unit/DevTools/PHPStan/Rules/Complexity/ExcessiveParameterCountRuleTest.php`
- Fixtures in `tests/Unit/DevTools/PHPStan/Rules/Complexity/Fixtures/`

### Part 4: PHPStan rule tips

Added `->tip()` to all 3 rules with LLM-friendly guidance:
- Parameter count: "Group related parameters into a VO or DTO..."
- Method length: "Extract logical sections into well-named private methods..."
- Class length: "Check whether this class has multiple responsibilities..."

### Part 5: Fix ExcessiveParameterCount violations (34→11 baseline entries)

**Phase 1: Webhook Envelope Pattern (Group A — 13 violations fixed)**
- Created `RawWebhookPayloadDTO` — collapses 5 scalar params into 1 for Handle*WebhookService::execute()
- Created `WebhookContextDTO` — replaces (eventTime, webhookId, topic) triplet in UseCase signatures
- Created `StockChangeDataDTO` — groups product identity + stock change fields for UpdateProductStockUseCase
- Updated 5 Handle services, 7 Use Cases, 5 Controllers, 4 test files

**Phase 2: Interface Pairs (Groups B1-B3 — 8 violations fixed)**
- Created `PriceUpdateAlertDataDTO` — groups 6 pricing notification params
- Created `VariantSkuNotificationDataDTO` — groups 6 variant SKU notification params
- Created `SupplierLinkParams` (Domain VO) — groups 4 supplier-link params
- Created `PriceSnapshot` (Domain VO) — groups 5 SCD2 price period fields
- Updated interfaces, implementations, callers, listeners, tests

**Phase 3: Variant SKU Generation (Group E — 2 violations fixed)**
- Created `VariationProcessingContextDTO` — groups batch-constant params (product, template, command, standardSignVariations)
- Updated StockItemParamsBuilderService::build() and GenerateVariantSkusUseCase::processVariation()

**Phase 4: Suppressions (Groups B4, C, D, F — 11 entries remain)**
- MixpanelTransport (3) — internal interface, params map 1:1 to HTTP concepts
- EloquentGateway (5) — generic database utility, wrapping would add ceremony
- CustomerAddress (1) — VO factory, parameter list IS the domain concept
- OrderModelMapper (2) — mapper receiving pre-loaded relations

## Decision Log

### 2026-03-29
- **Decision**: Use `DTO` suffix for all new DTOs per PHPArkitect naming rule
- **Why**: PHPArkitect enforces `*DTO` suffix for classes in `DTOs/` directories

### 2026-03-29
- **Decision**: Place `SupplierLinkParams` and `PriceSnapshot` in Domain layer as VOs
- **Why**: They represent domain concepts (supplier link parameters, price snapshot) independent of infrastructure

### 2026-03-29
- **Decision**: Keep `WebhookContextDTO` in Application layer, not Domain
- **Why**: Contains `WebhookTopic` enum which lives in Application layer

## Test Results

- 2766 tests passed, 6232 assertions

## Lint Results

- Pint: pass
- PHPStan: No errors
- PHPArkitect: No violations
- Deptrac: 0 violations
- TLint: LGTM

## PR Notes

### What
Added PHP code size and complexity rules to PHPStan (method length, class length, parameter count), then fixed 23 of 34 ExcessiveParameterCount violations by introducing parameter objects.

### Why
Enforce code size metrics that the frontend already has via ESLint, reducing method sprawl and parameter bloat in the PHP backend.

### Key Decisions
- 3 custom PHPStan rules with LLM-friendly tips
- 23 violations fixed with 8 new parameter objects (6 DTOs + 2 Domain VOs)
- 11 violations justified and kept in baseline
- Baseline reduced from 34 to 11 ExcessiveParameterCount entries

### Testing
- All 2766 existing tests pass
- All 5 linters pass clean
