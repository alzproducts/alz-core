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

### Part 3: Tests

Added `DevTools` testsuite to `phpunit.xml`.

Created test files + fixtures:
- `tests/Unit/DevTools/PHPStan/Rules/Complexity/ExcessiveMethodLengthRuleTest.php`
- `tests/Unit/DevTools/PHPStan/Rules/Complexity/ExcessiveClassLengthRuleTest.php`
- Fixtures in `tests/Unit/DevTools/PHPStan/Rules/Complexity/Fixtures/` with `App\` namespace (required for rules to fire)

## Test Results

_To be filled after test run_

## Lint Results

_To be filled after lint run_

## Handoff Notes

- If `make lint` reveals existing violations from the new rules, consider generating a PHPStan baseline per the plan (`vendor/bin/phpstan --generate-baseline=phpstan-complexity-baseline.neon`)
- Thresholds (50 lines methods, 300 lines classes, 10 constructor deps) can be adjusted after initial run
