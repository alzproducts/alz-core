# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Documentation Philosophy

**Keep this file succinct.** Use minimal format to convey maximum information:
- Bullet points over paragraphs
- Code examples over prose explanations
- Decision rationale in 1-2 sentences, not essays
- LLM-optimized: low token count, high information density

**When updating**: Add only what's essential. Remove outdated sections. Optimize for LLM parsing speed.

## ⚠️ Important: User Approval Required

**Discuss with user before proceeding** on:
- Clean Architecture decisions (naming, layer placement, pattern choices)
- Linting/PHPArkitect failures that aren't obvious syntax errors
- Any architectural trade-offs or deviations from established patterns

**Rationale**: These decisions shape the codebase long-term. User should make informed choices, not have them silently resolved.

## Tool Usage

### zen:challenge - Critical Thinking

**Use when**:
- Question seems biased or leading
- Unsure if recommendation provides actual value
- Following a pattern without questioning applicability
- Need neutral second opinion before proceeding

**Example**: "We need tests for X" → Challenge examines if X actually needs testing or just inflates metrics.

### JetBrains MCP - File Creation

**Do NOT use `mcp__jetbrains__create_new_file`** for creating new files. Use the standard `Write` tool instead.

**Rationale**: The JetBrains MCP `create_new_file` tool has unreliable behavior and doesn't integrate well with the validation hooks.

## Implementation Logs

When working on a GitHub issue with an associated plan document, maintain an implementation log at `.ai/docs/implementation/issue-{number}-{description}.md`.

**Key practices:**
- Create the log when starting work on a non-trivial feature
- Update the decision log as decisions are made, not after the fact
- Keep entries terse (bullet points, not prose)
- Read existing implementation logs at the start of conversations to restore context
- Use the PR Notes section to draft the PR description before creating the PR

See `.ai/docs/implementation/CLAUDE.md` for the full template and guidelines.

## ⚠️ Git Commit Policy

**NEVER create git commits unless explicitly requested by the user.**

- ❌ Do NOT stage files (`git add`)
- ❌ Do NOT create commits (`git commit`)
- ❌ Do NOT push to remote (`git push`)
- ✅ DO make code changes, run linters, run tests
- ✅ DO report when changes are ready to commit
- ✅ Only commit when user explicitly asks (e.g., "commit these changes", "create a commit")

**Rationale**: The user maintains full control over git history and commit timing.

**Always use `git mv`** when moving/renaming files. Preserves history; delete + create loses it.

## Development Environment

**PHP**: Native PHP 8.4 via Homebrew (not Docker/Sail)
**Services**: PostgreSQL 17 + Redis in Docker (services only)
**Octane**: Swoole (matches production)

### Quick Reference
```bash
docker compose up -d              # Start PostgreSQL + Redis
make db-setup                     # Create databases (first time)
php artisan migrate               # Run migrations
php artisan octane:start --watch  # Dev server with hot reload
make test-unit                    # Run unit tests (~5s, no external deps)
make test                         # Run all tests (unit + integration)
make lint                         # Run linters
```

### Why Native PHP?
- **6x faster** test execution (4.5s vs ~30s with Sail)
- **2-3x faster** linting and static analysis
- **Instant** IDE indexing (no Docker volume overhead)

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

## Interface Placement Rules

**Core Principle:** Interfaces live where they're USED, not where they're IMPLEMENTED.

**Correct Pattern:**
- Application defines: `Application/Contracts/MixpanelClientInterface`
- Application uses: `SyncAdSpendUseCase` uses the interface
- Infrastructure implements: `Infrastructure/Mixpanel/MixpanelClient implements MixpanelClientInterface`

**Why:** Dependency Inversion Principle - higher layers define contracts, lower layers fulfill them.

**Organization Rules:**
- No interfaces in Infrastructure layer (Infrastructure implements, doesn't define)
- All interfaces live in `/Contracts/` subdirectories within Domain or Application
- Contracts directories contain ONLY interfaces, never implementations

## Key Architectural Decisions

1. **Cache-first**: Default to caching, remove only when needed
2. **Queue everything**: Webhooks respond immediately, process async
3. **Supabase shared**: Same PostgreSQL database as Next.js frontend
4. **Production uses Octane**: We run long-running daemon processes (Laravel Octane) in production. Be cautious with date/time calculations in queue jobs—always calculate timestamps in `handle()` method, not in constructor, to ensure fresh evaluations on each execution (not stale values from job creation time).

## Exception Handling in Clean Architecture

**Layer Responsibility Summary**:
- **Domain**: Define business rule violations as exceptions (e.g., `InsufficientStockException`)
- **Infrastructure**: Catch SDK exceptions, translate to Domain exceptions before leaving layer
- **Application**: Rarely catches - only for business coordination (batch processing, transactions)
- **Presentation**: Catches only for delivery mechanism (queue retry, HTTP responses, console output)

**Golden Rules**:
1. Infrastructure does translation work, Application stays clean
2. Never catch just to log - Infrastructure logs before translating
3. Never return empty arrays to hide failures - throw exceptions
4. Let exceptions bubble unless you have specific reason to catch
5. For jobs: Use exceptions + Laravel's retry system (don't wrap in Result objects)

**Exception Flow**: `SDK Exception → [Infrastructure translates] → Domain Exception → [Application ignores] → [Presentation handles delivery] → Laravel`

See layer-specific guides for detailed patterns.

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

**CRITICAL**: We maintain strict code quality standards with four linters + mutation testing.

### ⚠️ Auto-Linting Hook

**`make lint` runs automatically** via Claude Code hook when you stop responding. **Do NOT manually run `make lint`** unless:
- You need to verify fixes mid-task before stopping
- You're debugging a specific linting issue

**Rationale**: Reduces redundant lint runs. The hook catches issues before user sees your response.

### Linters Configured
1. **Laravel Pint** (Code Style) - PER (PHP Evolving Recommendation) preset with strict rules
2. **PHPStan Level max** (Static Analysis) - Maximum strictness + 11 ShipMonk rules + bleeding edge
3. **PHP Insights** (Architecture/Quality) - Complexity, architecture, code quality metrics
4. **PHPArkitect** (Architecture Enforcement) - Clean Architecture layer boundaries + naming conventions
5. **Deptrac** (Layer Dependencies) - Analyzes `use` statement imports for CA violations
6. **Infection** (Mutation Testing) - Validates test quality by catching weak assertions (especially AI-generated tests)

**Why both PHPArkitect + Deptrac?** PHPArkitect checks type usage (`new`, `extends`, type hints) but misses `use` imports. Deptrac explicitly analyzes imports. Together they provide complete CA enforcement.

### Deptrac Whitelist Enforcement

Deptrac enforces **whitelist-only** external dependencies. Any new Composer package must be:
1. Added as a layer in `deptrac.yaml` with regex pattern
2. Explicitly allowed in the target layer's ruleset

**Allowed external packages by layer:**

| Layer | Allowed External Dependencies |
|-------|-------------------------------|
| Domain | `Webmozart\Assert` |
| Application | `Psr\*` interfaces |
| Infrastructure | Laravel, Spatie\LaravelData, Google\*, Webmozart\Assert |
| Presentation | Laravel, Symfony\HttpFoundation, Firebase\JWT |

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
make lint         # Pre-commit: Pint + PHPStan + PHPArkitect + Deptrac (~7s)
make lint-full    # Pre-push: All linters (~20-30s)
make deptrac      # Run Deptrac layer dependency analysis
make check        # Full validation: lint-full + tests
make test-ai      # Validate AI-generated tests (test + infection)
```

### Pint Style Fixes (Automated Approach)

**ALWAYS try auto-fixing with Pint before manually editing for style issues:**

```bash
php vendor/bin/pint <file-path>  # Auto-fix single file
make fix                         # Auto-fix all files
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

**For common linting errors** (e.g., `shipmonk.checkedExceptionInCallable`), see `.ai/docs/guides/common-linting-errors.md` for ranked solutions.

---

*See README.md for project overview and setup. See tests/CLAUDE.md for testing guidance.*
