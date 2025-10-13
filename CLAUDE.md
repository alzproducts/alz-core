# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Goal

Backend service for e-commerce webhooks and background jobs. Replaces legacy PHP app for 3-4 internal staff.

**Key Functions**: Process webhooks, sync orders/inventory/products, scheduled tasks
**Frontend**: Separate Next.js app using Supabase (already built)
**Deployment**: Railway (planned)

See detailed plan: `.ai/docs/plans/alz-core-initial-plan.md`

## Current Stack

- Laravel 12 (backend-only, no frontend)
- PHP 8.4+
- SQLite (development) → PostgreSQL/Supabase (production, planned)
- Redis (cache/queues, planned)
- Horizon + Telescope (monitoring, planned)

## Development Commands

**IMPORTANT**: All PHP and Composer commands MUST be run through Sail. Never use local PHP/Composer as it may be the wrong version or have platform compatibility issues.

**NOTE**: Do NOT run `sail up` or `sail down` - the user manages Sail container lifecycle manually.

### Using Sail (no local PHP required)
```bash
# First time: Install via Docker
docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    laravelsail/php84-composer:latest composer install --ignore-platform-reqs

docker run --rm -u "$(id -u):$(id -g)" -v "$(pwd):/var/www/html" -w /var/www/html \
    laravelsail/php84-composer:latest php artisan sail:install

# Start/stop
./vendor/bin/sail up -d
./vendor/bin/sail down

# Run commands
./vendor/bin/sail artisan test
./vendor/bin/sail artisan migrate
```

### With local PHP 8.4+
```bash
composer run setup    # First-time setup
composer run dev      # Start all services (server, queue, logs, vite)
composer run test     # Run tests
```

## Key Architectural Decisions

1. **Cache-first**: Default to caching, remove only when needed
2. **Thin SDK**: E-commerce API package (planned) stays simple, Laravel handles logic
3. **Queue everything**: Webhooks respond immediately, process async
4. **Supabase shared**: Same PostgreSQL database as Next.js frontend

## Modern PHP Standards

**Target**: PHP 8.4+ features and best practices

### Exception Handling
- **Use specific SPL exceptions** instead of generic `\Exception`
- Runtime failures → `RuntimeException`
- Invalid arguments → `InvalidArgumentException`
- Logic errors → `LogicException`
- See: [SPL Exceptions](https://www.php.net/manual/en/spl.exceptions.php)

### PHP 8.4 Features
- **Property Hooks**: Use where appropriate (getters/setters on properties)
  - ⚠️ Cannot replace interface-required methods
  - Good for internal class properties with validation/transformation logic
- **Asymmetric Visibility**: Use `public private(set)` for read-only properties
- **Array Functions**: Use `array_find()`, `array_find_key()`, `array_any()`, `array_all()`

### Type Safety
- Always use strict types: `declare(strict_types=1);`
- Use union types over docblock annotations: `string|int` not `@var string|int`
- Prefer readonly properties for immutable data
- Use enums over class constants for fixed sets

## Code Quality & Linting

**CRITICAL**: We maintain strict code quality standards with three linters:

### Linters Configured
1. **Laravel Pint** (Code Style) - PER (PHP Evolving Recommendation) preset with strict rules
2. **PHPStan Level max** (Static Analysis) - Maximum strictness + 11 ShipMonk rules + bleeding edge
3. **PHP Insights** (Architecture/Quality) - Complexity, architecture, code quality metrics

### Running Linters

**IMPORTANT**: Run all linters through Sail to ensure correct PHP version:

```bash
# Fast linting (pre-commit) - ~5-10 seconds
./vendor/bin/sail composer lint           # Run Pint + PHPStan (tests only, no fixes)

# Full linting (pre-push) - ~20-30 seconds
./vendor/bin/sail composer lint:full      # Run Pint + PHPStan + PHP Insights

# Auto-fix style issues
./vendor/bin/sail composer fix            # Auto-fix code style with Pint

# Individual linters
./vendor/bin/sail composer pint           # Fix code style with Pint
./vendor/bin/sail composer pint:test      # Test code style (dry-run)
./vendor/bin/sail composer analyse        # Run PHPStan static analysis
./vendor/bin/sail composer insights       # Run PHP Insights quality check

# Run everything (tests + linters)
./vendor/bin/sail composer check          # Run lint:full + tests
```

### Recommended Workflow
- **On save/frequent**: `./vendor/bin/sail composer fix` (auto-fix style, ~1s)
- **Before commit**: `./vendor/bin/sail composer lint` (Pint + PHPStan, ~5-10s)
- **Before push**: `./vendor/bin/sail composer lint:full` or `composer check` (all linters + tests, ~30s)
- **In CI/CD**: `composer check` (full validation)

### Linting Standards
- **All code MUST pass all three linters before commit**
- **PHPStan Level max** enforces maximum type safety with bleeding edge features
- **Strict comparisons required**: Use `===` instead of `==` (enforced by phpstan-strict-rules)
- **Deprecation warnings are errors**: Uses phpstan-deprecation-rules for upgrade path safety
- **All PHP files MUST include**: `declare(strict_types=1);`

### Pint Strict Rules (PER Preset)

**Why PER?** PHP Evolving Recommendation is the modern successor to PSR-12, supporting PHP 8+ features (enums, match, attributes).

**Strict Rules Enabled:**

1. **`declare_strict_types: true`**
   - Automatically adds `declare(strict_types=1);` to all PHP files
   - Prevents type juggling bugs and enforces explicit type handling

2. **`strict_comparison: true`**
   - Enforces `in_array($x, $y, true)` over `in_array($x, $y)`
   - Requires strict mode for array/string comparison functions
   - Prevents accidental type coercion (e.g., `"0" == 0` returning true)

3. **`strict_param: true`**
   - Forces strict parameters in functions like `array_search()`, `array_keys()`
   - Ensures consistent strict behavior across all comparison functions

4. **`mb_str_functions: true`** ⚠️ *Risky*
   - Converts `strlen()` → `mb_strlen()`, `strpos()` → `mb_strpos()`
   - **Why enabled**: E-commerce backend handles international data (product names, addresses)
   - Prevents Unicode bugs with multi-byte characters (emoji, Chinese, Arabic, etc.)
   - Requires `mbstring` extension (included in PHP core)

5. **`modernize_types_casting: true`** ⚠️ *Risky*
   - Converts `intval($x)` → `(int) $x`, `strval($x)` → `(string) $x`
   - **Why enabled**: Type casts are faster and more explicit than function calls
   - Prevents accidental function overrides

6. **`date_time_immutable: true`** ⚠️ *Risky*
   - Enforces `DateTimeImmutable` over `DateTime`
   - **Why enabled**: Prevents mutation bugs in date handling
   - Immutability = fewer bugs in background jobs and webhooks

### Quality Thresholds (PHP Insights)
- Code Quality: 90% minimum
- Complexity: 85% minimum
- Architecture: 90% minimum
- Style: 95% minimum

### ⚠️ IMPORTANT: Bypassing Linters

**NEVER bypass linting rules using suppression comments without explicit user approval.**

This includes:
- `@phpstan-ignore-line`
- `@phpstan-ignore-next-line`
- `@psalm-suppress`
- PHPStan baseline files
- Pint/PHP Insights exclusions

**If a linter reports an issue, the code MUST be fixed, not suppressed.**

Only bypass linting when:
1. User explicitly approves the suppression
2. It's a known false positive in a framework/package (document why)
3. Working around a temporary external dependency issue (add TODO)

---

*This file grows as we build. See plan for full roadmap.*
