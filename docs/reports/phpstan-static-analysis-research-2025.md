# PHPStan & PHP Static Analysis Research Report 2025

**Date:** 2025-12-12
**Purpose:** Comprehensive research on PHPStan rules, plugins, and static analysis best practices not enabled by default.

---

## Table of Contents

1. [Essential - Enable in Any Serious Project](#1-essential---enable-in-any-serious-project)
2. [Recommended - Strong Benefit, Low Controversy](#2-recommended---strong-benefit-low-controversy)
3. [Optional - For Stricter Teams](#3-optional---for-stricter-teams)
4. [Experimental / Specialized](#4-experimental--specialized)
5. [PHPStan vs Psalm Comparison](#5-phpstan-vs-psalm-comparison)
6. [Configuration Reference](#6-configuration-reference)
7. [Sources](#7-sources)

---

## 1. Essential - Enable in Any Serious Project

### 1.1 PHPStan Level 10 / max

**What:** PHPStan 2.0 introduced Level 10, which treats ALL `mixed` types strictly (not just explicit ones).

**Why Essential:** Level 10 catches edge cases you didn't know existed and prevents bugs you never would have considered.

```yaml
# phpstan.neon
parameters:
    level: max  # Dynamic alias for highest level (currently 10)
```

**Note:** `level: max` is preferred over `level: 10` as it auto-upgrades with PHPStan versions.

**Source:** [PHPStan 2.0 Release](https://phpstan.org/blog/phpstan-2-0-released-level-10-elephpants)

---

### 1.2 Bleeding Edge Configuration

**What:** Preview of next major version features, shipped in current stable release.

**Why Essential:**
- More capable analysis sooner
- Performance improvements (memory reduction)
- Smoother upgrades when next major version releases

```yaml
# phpstan.neon
includes:
    - vendor/phpstan/phpstan/conf/bleedingEdge.neon
```

**Note:** You don't need to be at level max to use bleeding edge. Rules activate at their configured levels.

**Source:** [What is Bleeding Edge?](https://phpstan.org/blog/what-is-bleeding-edge)

---

### 1.3 Built-in Checked Exception Rules

**What:** PHPStan has native checked exception support since v0.12.87. This is likely what you need to fix your 200 violations.

**Why Essential:** Enforces `@throws` documentation and catches missing exception handling.

```yaml
# phpstan.neon
parameters:
    exceptions:
        check:
            missingCheckedExceptionInThrows: true  # Report missing @throws
            tooWideThrowType: true                  # Report overly broad @throws
        uncheckedExceptionClasses:
            - LogicException           # Programming errors, shouldn't be caught
            - InvalidArgumentException
            - RuntimeException         # Optional: some teams check these too
```

**How It Works:**
- All exceptions are "checked" by default
- Checked exceptions must be caught OR declared in `@throws`
- Mark exceptions as "unchecked" to exclude them from enforcement

**Source:** [Bring Your Exceptions Under Control](https://phpstan.org/blog/bring-your-exceptions-under-control)

---

### 1.4 phpstan/phpstan-strict-rules

**What:** Official extra strict rules package for strongly typed code.

**Version:** 2.0.7 (Dec 2025)
**Install:** `composer require --dev phpstan/phpstan-strict-rules`

```yaml
# phpstan.neon (auto-included via extension-installer)
includes:
    - vendor/phpstan/phpstan-strict-rules/rules.neon
```

**Key Rules:**
- Require booleans in conditions (no truthy/falsy)
- `in_array()`, `array_search()` must use strict mode (`$strict = true`)
- Liskov Substitution Principle enforcement
- Disallow loose comparisons (`==`, `!=`)
- Disallow backtick operator
- Disallow `empty()` construct

**Granular Control:**
```yaml
parameters:
    strictRules:
        disallowedLooseComparison: true
        booleansInConditions: true
        uselessCast: true
        requireParentConstructorCall: true
        disallowedBacktick: true
        disallowedEmpty: true
        disallowedImplicitArrayCreation: true
        strictFunctionCalls: true
```

**Source:** [phpstan-strict-rules](https://github.com/phpstan/phpstan-strict-rules)

---

### 1.5 phpstan/phpstan-deprecation-rules

**What:** Detect usage of deprecated classes, methods, properties, constants, and traits.

**Install:** `composer require --dev phpstan/phpstan-deprecation-rules`

**Why Essential:** Catch deprecated code usage before upgrading dependencies.

**Source:** [PHPStan Extension Library](https://phpstan.org/user-guide/extension-library)

---

### 1.6 Critical Configuration Options (Not Enabled by Default)

These parameters provide significant safety but are disabled by default:

```yaml
# phpstan.neon
parameters:
    # Report typed properties not initialized in constructor
    checkUninitializedProperties: true

    # Stricter handling of benevolent union types
    checkBenevolentUnionTypes: true

    # Report accessing possibly non-existent array offsets
    reportPossiblyNonexistentGeneralArrayOffset: true
    reportPossiblyNonexistentConstantArrayOffset: true
```

**Source:** [PHPStan Config Reference](https://phpstan.org/config-reference)

---

## 2. Recommended - Strong Benefit, Low Controversy

### 2.1 shipmonk/phpstan-rules

**What:** ~40 super-strict rules from ShipMonk's production experience.

**Version:** 4.3.1 (Dec 2025)
**Install:** `composer require --dev shipmonk/phpstan-rules`

```yaml
# phpstan.neon
includes:
    - vendor/shipmonk/phpstan-rules/rules.neon
```

**Notable Rules:**
- Checked exception handling in closures (can't be tracked by PHPStan normally)
- Comparison operators only for `int|string|float|DateTimeInterface`
- Class suffix naming enforcement (Exception, Rule, Test, Command)
- Native typehints required for closures/arrow functions
- Readonly property enforcement for public properties

**Granular Control:**
```yaml
parameters:
    shipmonkRules:
        enableAllRules: false  # Then enable only what you want
        classSuffixNaming:
            superclassToSuffixMapping:
                \Exception: Exception
                \PHPUnit\Framework\TestCase: Test
```

**Source:** [shipmonk-rnd/phpstan-rules](https://github.com/shipmonk-rnd/phpstan-rules)

---

### 2.2 spaze/phpstan-disallowed-calls

**What:** Detect and ban dangerous/unwanted function calls with powerful re-allow rules.

**Install:** `composer require --dev spaze/phpstan-disallowed-calls`

```yaml
# phpstan.neon
includes:
    # Start with these bundled configs:
    - vendor/spaze/phpstan-disallowed-calls/disallowed-dangerous-calls.neon
    - vendor/spaze/phpstan-disallowed-calls/disallowed-execution-calls.neon
    - vendor/spaze/phpstan-disallowed-calls/disallowed-insecure-calls.neon
    - vendor/spaze/phpstan-disallowed-calls/disallowed-loose-calls.neon
```

**Bundled Configurations:**
| Config | Bans |
|--------|------|
| `dangerous-calls` | `var_dump()`, `print_r()`, `debug_backtrace()` |
| `execution-calls` | `exec()`, `shell_exec()`, `system()`, backtick operator |
| `insecure-calls` | `md5()`, `sha1()`, `mysql_query()`, `rand()` |
| `loose-calls` | `in_array()` without strict, `array_search()` without strict |

**Custom Rules:**
```yaml
parameters:
    disallowedFunctionCalls:
        - function: 'dd()'
          message: 'Remove debug statement'
        - function: 'dump()'
          message: 'Remove debug statement'
```

**Note:** This is NOT security defense against hostile developers (they can obfuscate). It's another pair of eyes for your own mistakes.

**Source:** [spaze/phpstan-disallowed-calls](https://github.com/spaze/phpstan-disallowed-calls)

---

### 2.3 tomasvotruba/cognitive-complexity

**What:** PHPStan rules measuring cognitive complexity of classes and methods.

**Version:** 1.0.0 (Dec 2024)
**Install:** `composer require --dev tomasvotruba/cognitive-complexity`

```yaml
# phpstan.neon
parameters:
    cognitive_complexity:
        class: 50      # Max class complexity
        function: 8    # Max method/function complexity
```

**What It Measures:** How difficult code is to understand by a reader (different from cyclomatic complexity which measures test paths).

**Includes:** Dependency tree detection - simple class with 10 dependencies is flagged as more complex than complex class with 2 dependencies.

**Source:** [tomasvotruba/cognitive-complexity](https://github.com/TomasVotruba/cognitive-complexity)

---

### 2.4 tomasvotruba/type-coverage

**What:** Measure type declaration coverage of your project.

**Install:** `composer require --dev tomasvotruba/type-coverage`

**Reports:**
- Native property type coverage
- Native parameter type coverage
- Native return type coverage

**Use With:** `staabm/phpstan-baseline-analysis` for trend reporting over time.

**Source:** [Packagist](https://packagist.org/packages/tomasvotruba/type-coverage)

---

### 2.5 staabm/phpstan-todo-by

**What:** TODO/FIXME comments with expiration dates that become PHPStan errors.

**Install:** `composer require --dev staabm/phpstan-todo-by`

```yaml
# phpstan.neon
includes:
    - vendor/staabm/phpstan-todo-by/extension.neon

parameters:
    todo_by:
        nonIgnorable: true  # Can't be baselined (default)
        referenceTime: 'now'
```

**Expiration Formats:**
```php
// By date
// TODO 2025-01-15: Remove deprecated method

// By version (project)
// TODO >2.0: Refactor this when we drop PHP 8.3 support

// By dependency version
// TODO laravel/framework:>11.0: Use new routing syntax

// By ticket (GitHub, JIRA, YouTrack)
// TODO #123: Remove workaround when issue is fixed
```

**Why Recommended:** Prevents tech debt from being forgotten. Errors appear when the condition is met.

**Source:** [staabm/phpstan-todo-by](https://github.com/staabm/phpstan-todo-by)

---

### 2.6 staabm/phpstan-dba

**What:** Database-aware static analysis for SQL queries.

**Install:** `composer require --dev staabm/phpstan-dba`

**Supports:** MySQL/MariaDB, PostgreSQL, with `doctrine/dbal`, `mysqli`, `PDO`

**Features:**
- Result set type inference
- SQL syntax error detection
- Placeholder/bound value mismatch detection
- Query plan analysis for performance issues
- Write query analysis (opt-in)

**Configuration:**
```php
// phpstan-dba-bootstrap.php
use staabm\PHPStanDba\QueryReflection\QueryReflection;
use staabm\PHPStanDba\QueryReflection\RuntimeConfiguration;
use staabm\PHPStanDba\QueryReflection\PdoQueryReflector;

$config = new RuntimeConfiguration();
$config->stringifyTypes(true);

QueryReflection::setupReflector(
    new PdoQueryReflector($pdo),
    $config
);
```

**Note:** Can be used alongside phpstan-doctrine for comprehensive database analysis.

**Source:** [staabm/phpstan-dba](https://github.com/staabm/phpstan-dba)

---

## 3. Optional - For Stricter Teams

### 3.1 thecodingmachine/phpstan-strict-rules

**What:** Additional strict rules based on TheCodingMachine best practices.

**Version:** Updated Dec 2025
**Install:** `composer require --dev thecodingmachine/phpstan-strict-rules`

**Rules Include:**
- Don't throw base `Exception` class - use subclasses
- No empty catch statements
- When re-throwing, pass caught exception as `$previous`
- Forbid direct superglobal access (`$_GET`, `$_POST`, etc.)

**Caveat:** Some rules may be controversial depending on your codebase patterns.

**Source:** [thecodingmachine/phpstan-strict-rules](https://github.com/thecodingmachine/phpstan-strict-rules)

---

### 3.2 ergebnis/phpstan-rules

**What:** Opinionated rules from the ergebnis organization.

**Version:** 2.12.0 (Sep 2025)
**Install:** `composer require --dev ergebnis/phpstan-rules`

**Notable Rules:**
- Report non-anonymous classes that are not `final`
- Report test classes without `Test` suffix
- Report closures with nullable return types
- Report functions invoked with named arguments

**Caveat:** The "all classes must be final" rule is controversial. Many teams disagree with this approach.

**Source:** [ergebnis/phpstan-rules](https://github.com/ergebnis/phpstan-rules)

---

### 3.3 symplify/phpstan-rules

**What:** 70+ rules for clean architecture, naming, and framework best practices.

**Version:** 14.9.5 (Dec 2025)
**Install:** `composer require --dev symplify/phpstan-rules`

```yaml
# phpstan.neon - Include specific rulesets
includes:
    - vendor/symplify/phpstan-rules/config/code-complexity-rules.neon
    - vendor/symplify/phpstan-rules/config/naming-rules.neon
    - vendor/symplify/phpstan-rules/config/static-rules.neon
    # Framework-specific:
    - vendor/symplify/phpstan-rules/config/doctrine-rules.neon
    - vendor/symplify/phpstan-rules/config/symfony-rules.neon
```

**Categories:**
- Clean architecture enforcement
- Logical error detection
- Naming conventions
- Class namespace location checks
- Accidental visibility override detection
- Symfony/Doctrine/PHPUnit specific rules

**Source:** [symplify/phpstan-rules](https://github.com/symplify/phpstan-rules)

---

### 3.4 ekino/phpstan-banned-code

**What:** Alternative to spaze for banning dangerous code.

**Install:** `composer require --dev ekino/phpstan-banned-code`

**Difference from spaze:** Simpler configuration, fewer features. Choose spaze if you need granular re-allow rules.

**Source:** [ekino/phpstan-banned-code](https://github.com/ekino/phpstan-banned-code)

---

### 3.5 pepakriz/phpstan-exception-rules

**What:** Alternative exception handling rules package.

**Install:** `composer require --dev pepakriz/phpstan-exception-rules`

**Key Concept:** Divides exceptions into:
- **RuntimeExceptions** (checked) - Must be caught or documented
- **LogicExceptions** (unchecked) - Programming errors, should never be caught

**When to Use:** If you need more control than PHPStan's built-in exception checking, or prefer this mental model.

**Source:** [pepakriz/phpstan-exception-rules](https://github.com/pepakriz/phpstan-exception-rules)

---

## 4. Experimental / Specialized

### 4.1 Psalm (Alongside PHPStan)

**What:** Alternative static analyzer with unique features PHPStan doesn't have.

**When to Use Psalm:**

| Feature | PHPStan | Psalm |
|---------|---------|-------|
| Taint Analysis (SQL injection, XSS) | Not native | ✅ Built-in |
| Immutability annotations | Limited | ✅ `@psalm-immutable` |
| Security sink detection | Via plugins | ✅ Native |
| Ecosystem size | Larger | Smaller |
| Laravel support | Excellent (Larastan) | Good |

**Taint Analysis Example:**
```php
// Psalm detects: $_GET flows to mysql_query without sanitization
$id = $_GET['id'];  // Taint source
$query = "SELECT * FROM users WHERE id = $id";
mysql_query($query);  // Taint sink - ERROR!
```

**Taint Types:**
- `INPUT_HTML` - XSS prevention
- `INPUT_SQL` - SQL injection prevention
- `INPUT_SHELL` - Shell injection prevention
- `INPUT_SECRET` - Prevent logging passwords
- `SYSTEM_SECRET` - Prevent echoing system secrets

**Recommendation:** Use PHPStan as primary. Add Psalm `--taint-analysis` for security-critical code paths.

**Source:** [Psalm Security Analysis](https://psalm.dev/docs/security_analysis/)

---

### 4.2 PHPStan Pro

**What:** Paid add-on with premium features.

**Pricing:**
- Individual: €7/month (€70/year)
- Team (up to 25): €70/month (€700/year)
- 30-day free trial

**Features:**
- Web UI for browsing errors (click to open in editor)
- Continuous analysis (watch mode)
- Interactive fixer wizards
- Supports unlimited projects

**When Worth It:**
- Large codebases where CLI output is overwhelming
- Teams wanting better error visualization
- Supporting open-source PHPStan development

**Start:** `phpstan --pro` or [account.phpstan.com](https://account.phpstan.com)

**Source:** [Introducing PHPStan Pro](https://phpstan.org/blog/introducing-phpstan-pro)

---

### 4.3 PHPMD (PHP Mess Detector)

**What:** Code quality metrics analyzer.

**Install:** `composer require --dev phpmd/phpmd`

**Metrics:**
| Metric | Threshold | Description |
|--------|-----------|-------------|
| Cyclomatic Complexity | 10 | Decision points in method |
| NPath Complexity | 200 | Acyclic execution paths |
| Class Length | - | Lines of code |
| Method Length | - | Lines of code |

**When to Use:** If you want traditional complexity metrics instead of/alongside tomasvotruba's cognitive complexity.

**Rulesets:** `cleancode`, `codesize`, `controversial`, `design`, `naming`, `unusedcode`

**Source:** [phpmd.org](https://phpmd.org/rules/codesize.html)

---

### 4.4 Tightenco Duster (Laravel-specific)

**What:** Combines multiple linting tools for comprehensive coverage.

**Install:** `composer require --dev tightenco/duster`

**Includes:**
- **TLint** - Laravel-specific issues not caught by other tools
- **PHP_CodeSniffer** - Sniffs that can't be auto-fixed
- **PHP CS Fixer** - Custom rules not in Pint
- **Pint** - Laravel's code style

**When to Use:** If you want comprehensive Laravel linting beyond Pint alone.

**Source:** [tightenco/duster](https://packagist.org/packages/tightenco/duster)

---

## 5. PHPStan vs Psalm Comparison

### 2025 Landscape

| Aspect | PHPStan | Psalm |
|--------|---------|-------|
| **Industry Adoption** | De facto standard | Strong second |
| **Laravel Support** | Larastan (official) | psalm/plugin-laravel |
| **Symfony Support** | phpstan-symfony | psalm-plugin-symfony |
| **Level Range** | 0-10 (2.0+) | 1-8 (reversed: 1=strictest) |
| **Config Format** | NEON | XML |
| **Full-time Maintainer** | Yes (@ondrejmirtes) | Part-time |
| **Plugin Ecosystem** | Larger | Smaller |
| **Taint Analysis** | Feature request | Built-in |
| **Immutability** | Limited | Advanced |

### Recommendation

**For most projects:** PHPStan only. The ecosystems overlap 90%+.

**Add Psalm when:**
- Security is critical (use `--taint-analysis`)
- You need advanced immutability enforcement
- You want two perspectives on a large refactor

**Running Both:**
```bash
# In CI
vendor/bin/phpstan analyse
vendor/bin/psalm --taint-analysis  # Security-focused run
```

---

## 6. Configuration Reference

### Complete Strict Configuration Example

```yaml
# phpstan.neon
includes:
    - vendor/phpstan/phpstan/conf/bleedingEdge.neon
    - vendor/phpstan/phpstan-strict-rules/rules.neon
    - vendor/phpstan/phpstan-deprecation-rules/rules.neon
    - vendor/spaze/phpstan-disallowed-calls/disallowed-dangerous-calls.neon
    - vendor/spaze/phpstan-disallowed-calls/disallowed-execution-calls.neon
    - vendor/shipmonk/phpstan-rules/rules.neon
    - vendor/staabm/phpstan-todo-by/extension.neon

parameters:
    level: max
    paths:
        - app
        - tests

    # Exception handling (fixes your 200 violations)
    exceptions:
        check:
            missingCheckedExceptionInThrows: true
            tooWideThrowType: true
        uncheckedExceptionClasses:
            - LogicException
            - InvalidArgumentException

    # Additional strict options
    checkUninitializedProperties: true
    checkBenevolentUnionTypes: true
    reportPossiblyNonexistentGeneralArrayOffset: true
    reportPossiblyNonexistentConstantArrayOffset: true

    # Cognitive complexity
    cognitive_complexity:
        class: 50
        function: 8

    # Custom banned calls
    disallowedFunctionCalls:
        - function: 'dd()'
          message: 'Remove debug statement before commit'
        - function: 'dump()'
          message: 'Remove debug statement before commit'
```

### Composer Dependencies

```json
{
    "require-dev": {
        "phpstan/phpstan": "^2.1",
        "phpstan/phpstan-strict-rules": "^2.0",
        "phpstan/phpstan-deprecation-rules": "^2.0",
        "phpstan/extension-installer": "^1.3",
        "larastan/larastan": "^3.0",
        "shipmonk/phpstan-rules": "^4.3",
        "spaze/phpstan-disallowed-calls": "^3.0",
        "tomasvotruba/cognitive-complexity": "^1.0",
        "staabm/phpstan-todo-by": "^0.1"
    }
}
```

---

## 7. Sources

### Official Documentation
- [PHPStan Documentation](https://phpstan.org/)
- [PHPStan Config Reference](https://phpstan.org/config-reference)
- [PHPStan Rule Levels](https://phpstan.org/user-guide/rule-levels)
- [PHPStan Extension Library](https://phpstan.org/user-guide/extension-library)
- [Psalm Documentation](https://psalm.dev/docs/)
- [Psalm Security Analysis](https://psalm.dev/docs/security_analysis/)

### GitHub Repositories
- [phpstan/phpstan-strict-rules](https://github.com/phpstan/phpstan-strict-rules)
- [shipmonk-rnd/phpstan-rules](https://github.com/shipmonk-rnd/phpstan-rules)
- [spaze/phpstan-disallowed-calls](https://github.com/spaze/phpstan-disallowed-calls)
- [staabm/phpstan-todo-by](https://github.com/staabm/phpstan-todo-by)
- [staabm/phpstan-dba](https://github.com/staabm/phpstan-dba)
- [TomasVotruba/cognitive-complexity](https://github.com/TomasVotruba/cognitive-complexity)
- [symplify/phpstan-rules](https://github.com/symplify/phpstan-rules)
- [ergebnis/phpstan-rules](https://github.com/ergebnis/phpstan-rules)
- [thecodingmachine/phpstan-strict-rules](https://github.com/thecodingmachine/phpstan-strict-rules)
- [pepakriz/phpstan-exception-rules](https://github.com/pepakriz/phpstan-exception-rules)

### Articles & Blog Posts
- [PHPStan 2.0 Released](https://phpstan.org/blog/phpstan-2-0-released-level-10-elephpants)
- [Bring Your Exceptions Under Control](https://phpstan.org/blog/bring-your-exceptions-under-control)
- [What is Bleeding Edge?](https://phpstan.org/blog/what-is-bleeding-edge)
- [Introducing PHPStan Pro](https://phpstan.org/blog/introducing-phpstan-pro)
- [PHP Architect - Cranking PHPStan to 10](https://www.phparch.com/magazine/2025/01/2025-01-cranking-phpstan-to-10/)
- [Top PHP Static Analysis Tools 2025](https://meh.dev/php-static-analysis-tools)
- [BackEndTea - Use PHPStan Bleeding Edge](https://backendtea.com/post/use-phpstan-bleeding-edge/)
- [thephp.cc - Psalm or PHPStan?](https://thephp.cc/articles/psalm-or-phpstan)

### Community Resources
- [PHPStan GitHub Discussions](https://github.com/phpstan/phpstan/discussions)
- [SymfonyOnline January 2025 - Custom PHPStan Rules](https://live.symfony.com/2025-online-january/schedule/crafting-custom-phpstan-rules-for-symfony-apps)
- [Packagist](https://packagist.org/) - Version and download statistics
