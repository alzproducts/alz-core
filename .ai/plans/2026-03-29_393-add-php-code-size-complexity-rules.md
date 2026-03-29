# Code Complexity Rules — Implementation Plan

## Context

The React frontend recently added ESLint complexity rules (function/class lengths, parameter limits). This audit found that our PHP backend has excellent coverage for **type safety**, **cognitive complexity**, and **architecture boundaries** — but has **no enforcement of code size metrics**: method length, class length, parameter count, etc.

**PHPMD was the natural choice but is NOT compatible with PHP 8.4** (broken on property hooks — issues #1219, #1271). Instead, we'll write custom PHPStan rules following the established pattern of our 24 existing rules, plus enable two quick wins from packages already installed.

---

## Changes

### Part 1: Quick Wins (existing packages, config-only)

#### 1A. Enable `dependency_tree` in cognitive-complexity

**File:** `phpstan.neon` (lines 133-135)

Add `dependency_tree: 10` to the existing `cognitive_complexity` block. This limits the number of constructor dependencies a class can have — catches "god objects" that inject too many services.

The package default is 150 (effectively unlimited). A threshold of **10** is a sensible starting point — strict enough to flag genuinely bloated classes, lenient enough to avoid noise on typical Laravel services.

```yaml
cognitive_complexity:
    class: 50
    function: 10
    dependency_tree: 10
```

#### 1B. Enable Symplify `ForeachCeptionRule`

**File:** `phpstan.neon` (line 28 area)

The entire `code-complexity-rules.neon` was skipped because it overlaps with cognitive-complexity. But `ForeachCeptionRule` (nested foreach detection) does NOT overlap — it catches a specific anti-pattern that cognitive complexity only penalises indirectly.

Rather than including the whole file (which has 6 rules, some overlapping), register just the one rule in `phpstan-custom-rules.neon`:

**File:** `phpstan-custom-rules.neon` — add to rules list:

```neon
    # Complexity: nested foreach detection (from symplify, enabled individually to avoid overlap)
    - Symplify\PHPStanRules\Rules\Complexity\ForeachCeptionRule
```

---

### Part 2: New Custom PHPStan Rules (code size metrics)

Create 3 new rules in `app/DevTools/PHPStan/Rules/Complexity/`:

#### 2A. `ExcessiveMethodLengthRule`

- **Node type:** `ClassMethod` (and `Function_` for standalone functions)
- **What it checks:** Total lines between opening and closing braces (`endLine - startLine`). Counts all lines including blanks/comments — matches PHPMD and ESLint conventions.
- **Threshold:** 50 lines (configurable — PHPMD's default of 100 is too lenient, ESLint's typical `max-lines-per-function` is 30-50; using 50 since we count all lines)
- **Identifier:** `alz.excessiveMethodLength`
- **Scope:** Only `App\` namespace classes (PHPStan doesn't scan `tests/` — no exclusion needed)

#### 2B. `ExcessiveClassLengthRule`

- **Node type:** `Class_`
- **What it checks:** Total lines in class body (`endLine - startLine`). Counts all lines — matches PHPMD and ESLint conventions.
- **Threshold:** 300 lines (PHPMD default 1000 is too lenient)
- **Identifier:** `alz.excessiveClassLength`
- **Scope:** Only `App\` namespace classes (PHPStan doesn't scan `tests/` — no exclusion needed). Migration files are in `database/` which IS scanned — exclude `Database\Migrations` namespace.

#### ~~2C. `ExcessiveParameterListRule`~~ — DROPPED

Removed: `dependency_tree: 10` (Part 1A) already limits constructor dependencies. Non-constructor methods naturally have few params in this codebase. The exemption logic for VOs/DTOs vs services added complexity for minimal value.

### Rule registration

**File:** `phpstan-custom-rules.neon` — add new batch:

```neon
    # Batch 7: Code complexity / size rules
    - App\DevTools\PHPStan\Rules\Complexity\ExcessiveMethodLengthRule
    - App\DevTools\PHPStan\Rules\Complexity\ExcessiveClassLengthRule
```

### Rule tests

Each rule needs a test in `tests/Unit/DevTools/PHPStan/Rules/Complexity/` following the existing pattern (PHPStan `RuleTestCase`). Each test will have fixture files in a `Fixtures/` subdirectory.

---

## Files to Create/Modify

### Modified
| File | Change |
|------|--------|
| `phpstan.neon` | Add `dependency_tree: 10` to cognitive_complexity block |
| `phpstan-custom-rules.neon` | Add ForeachCeptionRule + 3 new Complexity rules |

### Created
| File | Purpose |
|------|---------|
| `app/DevTools/PHPStan/Rules/Complexity/ExcessiveMethodLengthRule.php` | Max method length (50 lines) |
| `app/DevTools/PHPStan/Rules/Complexity/ExcessiveClassLengthRule.php` | Max class length (300 lines) |
| `tests/Unit/DevTools/PHPStan/Rules/Complexity/ExcessiveMethodLengthRuleTest.php` | Tests + fixtures |
| `tests/Unit/DevTools/PHPStan/Rules/Complexity/ExcessiveClassLengthRuleTest.php` | Tests + fixtures |

### Existing patterns to follow
| Reference | Path |
|-----------|------|
| Rule implementation pattern | `app/DevTools/PHPStan/Rules/Architecture/NoStaticPropertiesRule.php` |
| Rule registration | `phpstan-custom-rules.neon` (batched by category) |
| Rule test pattern | `tests/Unit/DevTools/PHPStan/Rules/` (existing tests) |

---

## Thresholds Summary

| Rule | Threshold | Rationale |
|------|-----------|-----------|
| Constructor dependencies | 10 | Below this is restrictive for Laravel services; above invites god objects |
| Method length | 50 lines | Counts all lines (incl. blanks); matches ESLint range when accounting for whitespace |
| Class length | 300 lines | Generous enough for domain-rich classes, catches genuine bloat |
| ~~Parameter count~~ | ~~5~~ | Dropped — dependency_tree covers constructors; methods naturally have few params |
| Cognitive complexity (function) | 10 | Already set — no change |
| Cognitive complexity (class) | 50 | Already set — no change |

All thresholds can be adjusted after the initial run reveals how many violations exist.

---

## Verification

1. `make lint` — should pass (or reveal existing violations to baseline/address)
2. `make test` — new rule tests pass
3. If `make lint` reveals violations from the new rules:
   - Review them — are they genuine complexity issues or false positives?
   - Adjust thresholds if needed (e.g., bump method length to 50)
   - For legitimate violations that we want to fix later: generate a PHPStan baseline (`vendor/bin/phpstan --generate-baseline=phpstan-complexity-baseline.neon`) and include it in `phpstan.neon`. This is cleaner than individual `@phpstan-ignore` annotations for bulk existing violations.

---

## Out of Scope (Noted for Future)

- **PHPMD**: Revisit when PHP 8.4 support lands (track issue #1219)
- **PHP Insights** (`nunomaduro/phpinsights`): Laravel-focused quality tool, worth evaluating if we want a dashboard view
- **Churn-PHP**: High churn + high complexity analysis — useful as periodic health check
- **Coupling metrics**: Deptrac handles layer-level coupling; object-level coupling (like PHPMD's `CouplingBetweenObjects`) could be a future custom rule
- **Max public methods per class**: Could add as a 4th rule later
