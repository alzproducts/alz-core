# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Goal

Backend service for e-commerce webhooks and background jobs. Replaces legacy PHP app for 3-4 internal staff.

**Team Structure**: Solo developer (1 person), 3-4 internal staff users
**Key Functions**: Process webhooks, sync orders/inventory/products, scheduled tasks
**Frontend**: Separate Next.js app using Supabase (already built)
**Deployment**: Railway (planned)

**Development Philosophy**: Portfolio piece demonstrating modern PHP best practices. Previous PHP project became unmaintainable due to poor architecture—this project aims to establish clean architecture from day one.

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

### Assertions (webmozart/assert)

**Use for:** Internal developer contracts (preconditions, business invariants, value objects)
**Don't use for:** External input validation (use Laravel Request/Validator instead)

**Examples:**
```php
// Domain value objects
class Money {
    public function __construct(int $cents, string $currency) {
        Assert::greaterThanEq($cents, 0);
        Assert::length($currency, 3);
    }
}

// Service preconditions
public function applyDiscount(Order $order, DiscountCode $code) {
    Assert::true($order->isEligibleForDiscounts());
}
```

**PHPStan Integration:** Provides type narrowing after assertions (e.g., `Assert::string($x)` tells PHPStan `$x` is non-null string)

## Code Quality & Linting

**CRITICAL**: We maintain strict code quality standards with four linters + mutation testing:

### Linters Configured
1. **Laravel Pint** (Code Style) - PER (PHP Evolving Recommendation) preset with strict rules
2. **PHPStan Level max** (Static Analysis) - Maximum strictness + 11 ShipMonk rules + bleeding edge
3. **PHP Insights** (Architecture/Quality) - Complexity, architecture, code quality metrics
4. **PHPArkitect** (Architecture Enforcement) - Clean Architecture layer boundaries + naming conventions
5. **Infection** (Mutation Testing) - Validates test quality by catching weak assertions (especially AI-generated tests)

### Rector (Code Refactoring & Modernization)

**Purpose**: Automated code refactoring and PHP/Laravel version upgrades.

**When to Use Rector**:
- **PHP version upgrades**: Upgrading PHP 8.4 → 8.5/9.0
- **Laravel version upgrades**: Laravel 12 → 13 → 14
- **Deprecation fixes**: Converting deprecated patterns to modern equivalents
- **Code modernization**: Applying new PHP features (attributes, enums, match expressions)

**Key Differences from Pint**:
- **Pint**: Code style only (formatting, spacing, brackets)
- **Rector**: Code structure (refactoring, API migrations, language upgrades)
- **Workflow**: Rector → Pint → PHPStan → Tests

**Running Rector**:

```bash
# Preview changes (always run first!)
./vendor/bin/sail composer rector:dry-run

# Apply refactorings
./vendor/bin/sail composer rector

# Rector + Pint combo (recommended)
./vendor/bin/sail composer refactor
```

**Configuration**: `rector.php` - Conservative rules targeting:
- PHP 8.4 features
- Laravel 12.0 best practices
- PHPUnit 10+ attribute conversions (`@test` → `#[Test]`)
- Dead code removal
- Early returns and type declarations

**Important Notes**:
- ⚠️ Always run `rector:dry-run` first on large changes
- ✅ Rector is manual-only (not in git hooks) for intentional refactoring
- 🔄 Output needs formatting - always run Pint after Rector
- 📊 Industry data: Manual upgrades take 3 months, Rector reduces to 3 days

### Running Linters

**IMPORTANT**: Run all linters through Sail to ensure correct PHP version:

```bash
# Fast linting (pre-commit) - ~5-10 seconds
./vendor/bin/sail composer lint           # Run Pint + PHPStan + PHPArkitect (tests only, no fixes)

# Full linting (pre-push) - ~20-30 seconds
./vendor/bin/sail composer lint:full      # Run Pint + PHPStan + PHP Insights + PHPArkitect

# Auto-fix style issues
./vendor/bin/sail composer fix            # Auto-fix code style with Pint

# Individual linters
./vendor/bin/sail composer pint           # Fix code style with Pint
./vendor/bin/sail composer pint:test      # Test code style (dry-run)
./vendor/bin/sail composer analyse        # Run PHPStan static analysis
./vendor/bin/sail composer insights       # Run PHP Insights quality check
./vendor/bin/sail composer phparkitect    # Run PHPArkitect architecture checks

# Run everything (tests + linters)
./vendor/bin/sail composer check          # Run lint:full + tests
```

### Recommended Workflow
- **On save/frequent**: `./vendor/bin/sail composer fix` (auto-fix style, ~1s)
- **Before commit**: `./vendor/bin/sail composer lint` (Pint + PHPStan + PHPArkitect, ~5-10s)
- **Before push**: `./vendor/bin/sail composer lint:full` or `composer check` (all linters + tests, ~30s)
- **In CI/CD**: `composer check` (full validation)

### Git Hooks (Automated)
- **Pre-commit**: Pint + PHPStan + PHPArkitect (runs automatically on `git commit`)
- **Pre-push**: Pest tests + PHP Insights + PHPArkitect (runs automatically on `git push`)

### Linting Standards
- **All code MUST pass all four linters before commit**
- **PHPStan Level max** enforces maximum type safety with bleeding edge features
- **Strict comparisons required**: Use `===` instead of `==` (enforced by phpstan-strict-rules)
- **Deprecation warnings are errors**: Uses phpstan-deprecation-rules for upgrade path safety
- **All PHP files MUST include**: `declare(strict_types=1);`
- **Clean Architecture enforced**: PHPArkitect prevents layer violations and enforces naming conventions

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

### PHPArkitect Architecture Rules

**Purpose**: Enforces Clean Architecture boundaries from day one, preventing the architectural decay that plagued the previous project.

**4 Layers Enforced**:

```
App\Domain         → Business logic (Order, Product, calculations)
App\Application    → Use cases (SyncOrdersUseCase, ProcessWebhookUseCase)
App\Infrastructure → Implementations (EloquentOrderRepository, ApiClient)
App\Presentation   → Entry points (Controllers, Console commands, Jobs)
```

**8 Rules Active** (see `phparkitect.php` for full details):

1. **Domain Must Be Self-Contained** - No dependencies on other layers
2. **Application Only Depends on Domain** - Use cases depend on domain interfaces
3. **Infrastructure Implements Domain/Application** - Concrete implementations live here
4. **Presentation Uses Application Services** - Controllers delegate to use cases
5. **Controllers Must End With "Controller"** - Naming convention enforcement
6. **Application Services Must End With "UseCase" or "Service"**
7. **Repositories Must End With "Repository"**
8. **API Clients Must End With "Client"**

**Key Benefits**:
- ✅ Violations caught immediately on 9-file codebase (easy to fix)
- ✅ Real-time learning through descriptive error messages
- ✅ Prevents "controller bloat" and "business logic in HTTP layer"
- ✅ Ensures proper naming from first class created

**Pragmatic Laravel Approach**:
- ✅ Eloquent Models allowed in Domain (pragmatic choice)
- ✅ Collections, Support classes allowed
- ❌ HTTP, Console, Queue, Facades forbidden in Domain

### Infection (Mutation Testing)

**Purpose**: Validates test quality by catching weak AI-generated assertions.

**Quick Start**:
```bash
# After AI generates tests, run:
./vendor/bin/sail composer test:ai
```

**What It Catches**: `assertNotNull()` → should be `assertEquals('value', ...)`, `assertTrue()` → should be `assertSame(200, ...)`, etc.

**See `tests/CLAUDE.md` for detailed AI test validation workflow, common patterns, and interpretation guide.**

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
