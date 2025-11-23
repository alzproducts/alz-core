# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## ⚠️ Git Commit Policy

**NEVER create git commits unless explicitly requested by the user.**

- ❌ Do NOT stage files (`git add`)
- ❌ Do NOT create commits (`git commit`)
- ❌ Do NOT push to remote (`git push`)
- ✅ DO make code changes, run linters, run tests
- ✅ DO report when changes are ready to commit
- ✅ Only commit when user explicitly asks (e.g., "commit these changes", "create a commit")

**Rationale**: The user maintains full control over git history and commit timing.

## ⚠️ Sail Requirements

- **All PHP/Composer commands MUST run through Sail** (never local PHP)
- **Do NOT run `sail up` or `sail down`** - user manages container lifecycle manually

---

## Clean Architecture

This project follows **Clean Architecture** (Robert C. Martin) — dependencies point inward, outer layers depend on inner layers, never the reverse.

### Layers (Outer → Inner)

- **Presentation** (`App\Presentation`) — Entry points: HTTP controllers, console commands. Delegates to Application layer. *Naming: `*Controller`*

- **Infrastructure** (`App\Infrastructure`) — External world: API clients, database repositories, SDK wrappers. Implements Domain interfaces. Validates external data with exceptions. *Naming: `*Client`, `*Repository`*

- **Application** (`App\Application`) — Use cases: orchestrates Domain objects and Infrastructure services to accomplish tasks. Contains jobs, transformers. *Naming: `*UseCase`, `*Service`*

- **Domain** (`App\Domain`) — Pure business logic: value objects, entities, interfaces, domain exceptions. Zero external dependencies. Validates internal contracts with assertions.

### Key Rules

1. **Domain** depends on nothing (only PHP built-ins, `Webmozart\Assert`)
2. **Application** depends only on Domain
3. **Infrastructure** implements Domain interfaces, can use external SDKs/Laravel
4. **Presentation** calls Application use cases, never Infrastructure directly
5. **Validation**: External data → exceptions (Infrastructure), internal contracts → assertions (Domain)

### Spatie LaravelData (DTOs)

**Rule**: ❌ **NOT allowed in Domain layer**. Domain must stay framework-independent.

**Use in Application Layer**: Transform Domain objects to API response DTOs.
```php
#[MapOutputName(SnakeCaseMapper::class)]
final class RatingForApiDTO extends Data {
    public function __construct(
        public readonly string $sku,
        public readonly float $averageRating,
    ) {}
}
```

**Use in Infrastructure Layer**: Parse external API responses and represent them as type-safe objects.
```php
#[MapInputName(SnakeCaseMapper::class)]
final class Rating extends Data {
    public function __construct(
        public readonly string $sku,
        public readonly float $averageRating,
    ) {}
}
```

## Key Architectural Decisions

1. **Cache-first**: Default to caching, remove only when needed
2. **Queue everything**: Webhooks respond immediately, process async
3. **Supabase shared**: Same PostgreSQL database as Next.js frontend

## Modern PHP Standards

**Target**: PHP 8.4+ features and best practices

### Exception Handling
- **Use specific SPL exceptions** instead of generic `\Exception`
- Runtime failures → `RuntimeException`
- Invalid arguments → `InvalidArgumentException`
- Logic errors → `LogicException`

### PHP 8.4 Features
- **Property Hooks**: Use where appropriate (getters/setters on properties)
- **Asymmetric Visibility**: Use `public private(set)` for read-only properties
- **Array Functions**: Use `array_find()`, `array_find_key()`, `array_any()`, `array_all()`
- **Static Functions**: Always use static methods and closures for pure/stateless operations (transformations, utilities, factories). Never use static properties for state—Octane persists them across requests.
- **Readonly Classes**: Mark classes as `readonly` when all properties are immutable (DTOs, value objects, transformers)

### Type Safety
- Always use strict types: `declare(strict_types=1);`
- Use union types over docblock annotations: `string|int` not `@var string|int`
- Prefer readonly properties for immutable data
- Use enums over class constants for fixed sets

### Import & Use Statements
- **All classes must be imported** with `use` statements—including in docblocks (`@throws`, `@param`, `@return`)

### Assertion & Validation Quick Reference

#### Runtime Assertions (Development Only)
Zero cost in production when `zend.assertions=-1`

- **PHP assert()** - Built-in, compiles out in production
- **webmozart/assert** - Fluent API with 100+ methods, PHPStan integration

**Use for:** Internal contracts, preconditions in private methods, class invariants, logical impossibilities
**Never for:** User input, API parameters, security checks, business validation

#### Static Analysis (Compile-time)
Pure documentation, zero runtime cost

- **PHPStan annotations** - `@phpstan-assert`, `@phpstan-assert-if-true`
- **Larastan** - PHPStan + Laravel extensions

**Use for:** Type narrowing at Level 8, custom validation function contracts

#### Testing Assertions (Test-only)

- **Pest** - Modern test framework with expectation API
- **PHPUnit** - Traditional assertions

**Use for:** Verifying behavior in test suites

#### Validation (Always Active)
Remains active in production, handles untrusted input

- **Laravel Validator** - Framework-integrated, 80+ rules, Form Requests

**Use for:** User input, API requests/responses, external data, security boundaries

#### Decision Tree

1. **External/untrusted data** → Laravel Validator
2. **Internal contracts** → Runtime assertions (webmozart/assert)
3. **Type narrowing** → Static analysis annotations
4. **Test verification** → Pest expectations

## Build System: Makefile vs Composer

**Single Source of Truth**: Makefile owns all build/test/quality command implementations. The `composer.json` scripts delegate to Makefile targets.

**Run commands via Make**: `make lint`, `make test`, etc. Run `make help` for all targets.

## Code Quality & Linting

**CRITICAL**: We maintain strict code quality standards with four linters + mutation testing:

### Linters Configured
1. **Laravel Pint** (Code Style) - PER (PHP Evolving Recommendation) preset with strict rules
2. **PHPStan Level max** (Static Analysis) - Maximum strictness + 11 ShipMonk rules + bleeding edge
3. **PHP Insights** (Architecture/Quality) - Complexity, architecture, code quality metrics
4. **PHPArkitect** (Architecture Enforcement) - Clean Architecture layer boundaries + naming conventions
5. **Infection** (Mutation Testing) - Validates test quality by catching weak assertions (especially AI-generated tests)

### Rector (Code Refactoring)

For PHP/Laravel upgrades and code modernization. **Manual-only** (not in git hooks).

```bash
make rector-dry-run   # Preview changes (always run first!)
make rector           # Apply refactorings
make refactor         # Rector + Pint combo (recommended)
```

### Running Linters

**Primary commands** (run `make help` for full list):

```bash
make fix          # Auto-fix code style with Pint
make lint         # Pre-commit: Pint + PHPStan + PHPArkitect (~5-10s)
make lint-full    # Pre-push: All linters (~20-30s)
make check        # Full validation: lint-full + tests
make test-ai      # Validate AI-generated tests (test + infection)
```

### Pint Style Fixes (Automated Approach)

**ALWAYS try auto-fixing with Pint before manually editing for style issues:**

```bash
./vendor/bin/sail php vendor/bin/pint <file-path>  # Auto-fix single file
make fix                                             # Auto-fix all files
```

Pint can auto-fix ~95% of style issues (trailing newlines, spacing, imports ordering, etc.). Only manually edit code if:
1. Pint cannot auto-fix the specific issue
2. The issue requires semantic changes (not just formatting)

### Git Hooks (Automated)
- **Pre-commit**: Pint + PHPStan + PHPArkitect (runs automatically on `git commit`)
- **Pre-push**: Pest tests + PHP Insights + PHPArkitect (runs automatically on `git push`)

### PHPArkitect Naming Conventions

Enforced by `phparkitect.php` (layer dependencies defined in Clean Architecture above):

- Controllers → `*Controller`
- Application services → `*UseCase` or `*Service`
- Repositories → `*Repository`
- API clients → `*Client`

### Test Generation with zen:testgen

**Always use `zen:testgen` MCP when creating test suites.** It analyzes code structure, identifies edge cases, and generates mutation-resistant tests.

After generation, validate with both mutation engines on the specific class:
```bash
vendor/bin/infection --filter=YourClass.php --min-msi=80
vendor/bin/pest --mutate --class=App\\Domain\\YourClass --min=85
```
Fix escaped mutants until both pass.

### ⚠️ IMPORTANT: Bypassing Linters

**NEVER bypass linting rules** (`@phpstan-ignore`, `@psalm-suppress`, baseline files, etc.) **without explicit user approval.**

If a linter reports an issue, fix the code—don't suppress it. Only bypass when:
1. User explicitly approves
2. Known false positive in framework/package (document why)
3. Temporary external dependency issue (add TODO)

---

*See README.md for project overview and setup. See tests/CLAUDE.md for testing guidance.*
