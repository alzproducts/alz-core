# PHPUnit/Pest Deprecation Handling in PHP 8.4+

This guide documents how to handle PHP deprecation warnings that cause test suite failures, particularly with PHPUnit 12's `failOnWarning="true"` default behavior.

---

## The Problem

Your tests pass (1941 tests, 4665 assertions), but `make test` exits with code 1. PHPUnit/Pest report deprecation warnings from third-party packages that you cannot fix.

**Typical scenario:**
- PHP 8.4 deprecates implicit nullable parameters (`$param = null` without `?Type`)
- A vendor package (e.g., Google Ads SDK) uses this deprecated pattern
- PHPUnit 12 with `failOnWarning="true"` treats the deprecation as a failure
- Your CI fails despite all tests passing

---

## Root Cause: Autoload Timing

**Critical insight:** PHPUnit's error handler is only active during test execution, not during class autoloading.

When Composer autoloads a class file, PHP triggers deprecations immediately. This happens *before* PHPUnit's error handler is registered, so:

- `failOnDeprecation="false"` in `phpunit.xml` → **Ignored** (not yet active)
- `--do-not-fail-on-deprecation` CLI flag → **Ignored** (handler not registered)
- `ignoreIndirectDeprecations="true"` → **Ignored** (handler not registered)
- PHPUnit baseline (`--generate-baseline`) → **Fails** with parallel/random test order

---

## Failed Approaches (Don't Try These)

| Approach | Why It Fails |
|----------|--------------|
| `failOnDeprecation="false"` in phpunit.xml | Pest ignores PHPUnit's deprecation settings |
| `--do-not-fail-on-deprecation` CLI flag | PHPUnit's error handler isn't active during autoload |
| PHPUnit baseline (`--generate-baseline`) | Baseline stores test location, fails with random/parallel order |
| `ignoreIndirectDeprecations="true"` | Autoload deprecations happen before handler is active |
| Inline `error_reporting()` in test file | Partial: works for single test, full suite still triggers in parallel |

---

## Solutions (Best → Worst)

### 1. Upgrade the Vendor Package (Best)

If a newer version fixes the deprecation, upgrade. Track pending upgrades:

```php
// todo.php (with phpstan-todo-by)
/**
 * @todo Upgrade google/ads when PHP 8.4 compatible
 * @phpstan-todo-by 2025-03
 */
```

### 2. Isolate the Offending Tests

If specific tests trigger the autoload, isolate them in a separate test suite that runs without `--parallel`:

```xml
<!-- phpunit.xml -->
<testsuite name="Deprecated">
    <directory>tests/Deprecated</directory>
</testsuite>
```

Run separately: `vendor/bin/pest --testsuite=Deprecated` (no parallel flag).

### 3. Disable the Offending Test File Temporarily

If a single test file triggers autoload of problematic classes:

```php
// tests/ArchitectureTest.php
<?php

declare(strict_types=1);

// Temporarily disabled: Google Ads SDK triggers PHP 8.4 deprecation
// during autoload, before PHPUnit's error handler is active.
// Tracked in todo.php for re-enablement after SDK upgrade.
```

**Important:** Create a tracking mechanism (`todo.php`, GitHub issue) to re-enable.

### 4. Suppress at PHP Level (Last Resort)

In `bootstrap/testing.php` or a custom PHPUnit bootstrap:

```php
// Suppress specific deprecation before autoload
set_error_handler(function ($severity, $message, $file) {
    if ($severity === E_DEPRECATED && str_contains($file, 'vendor/google/ads')) {
        return true; // Suppress
    }
    return false; // Let PHPUnit handle
}, E_DEPRECATED);
```

**Warning:** This hides ALL deprecations from that path, including ones you should fix.

---

## Test Suite Overlap Warnings (Bonus Issue)

PHPUnit 12 with `failOnWarning="true"` also fails on test suite overlap warnings.

**Symptom:** Warning about `tests/Unit/Domain` being a subset of `tests/Unit`.

**Fix:** Restructure test suites to avoid overlap. Laravel's `Unit`/`Feature` split conflicts with Clean Architecture layers:

```xml
<!-- Before: Overlapping -->
<testsuite name="Unit">
    <directory>tests/Unit</directory>
</testsuite>
<testsuite name="Domain">
    <directory>tests/Unit/Domain</directory> <!-- Subset of Unit! -->
</testsuite>

<!-- After: Non-overlapping -->
<testsuite name="Domain">
    <directory>tests/Unit/Domain</directory>
</testsuite>
<testsuite name="Application">
    <directory>tests/Unit/Application</directory>
</testsuite>
<testsuite name="Infrastructure">
    <directory>tests/Unit/Infrastructure</directory>
</testsuite>
```

---

## Decision Tree

```
Tests pass but exit code 1?
    ↓
Is it a deprecation warning?
    → YES: ↓
    → NO: Check for test suite overlap warnings

Is the deprecation from YOUR code?
    → YES: Fix the deprecated code
    → NO (vendor): ↓

Can you upgrade the vendor package?
    → YES: Upgrade + track in todo.php
    → NO: ↓

Can you isolate the triggering test?
    → YES: Move to separate suite, run without --parallel
    → NO: ↓

Is it a single test file?
    → YES: Temporarily disable + track for re-enablement
    → NO: Consider PHP-level suppression (last resort)
```

---

## Key Takeaways

1. **Autoload timing is everything** — PHPUnit config only applies during test execution, not class loading
2. **`failOnWarning="true"` catches more than you expect** — Test suite overlap, deprecations, notices
3. **Track disabled tests** — Always create a reminder to re-enable when the underlying issue is fixed
4. **Parallel execution complicates isolation** — Deprecations can fire from any test process during autoload

---

## References

- [PHPUnit 12 Migration Guide](https://docs.phpunit.de/en/12.0/migration.html)
- [PHPUnit Error Handling](https://docs.phpunit.de/en/12.0/error-handling.html)
- [GitHub Issue #5937 - Deprecation handling](https://github.com/sebastianbergmann/phpunit/issues/5937)
- Related codebase files:
  - `phpunit.xml` — Test suite configuration
  - `todo.php` — Tracked deprecation reminders
  - `tests/ArchitectureTest.php` — Example of temporarily disabled test