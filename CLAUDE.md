# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## ⚠️ Important: Stop Hooks

**Account-level stop hooks run automatically when you finish responding.** These hooks execute:
- `make fix` — Auto-fix code style issues
- `make lint` — Run all linters (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
- `make test` — Run full test suite

**Hooks run in a loop**: If failures occur, fix the issues and stop again—hooks will re-run automatically.

**Do NOT manually run these commands** during normal usage. Only run them if:
- You need to verify a fix mid-task before stopping
- You're debugging an issue that didn't originate from a stop hook

---

## ⚠️ Important: Use Make Commands

**ALWAYS use Makefile commands instead of direct tool invocations.**

- ✅ `make test` — Pre-approved, runs without user intervention
- ❌ `php vendor/bin/pest` — Requires manual user approval every time

**Rationale**: Make commands are whitelisted in the allow-list. Direct commands require user approval, slowing down workflow.

**Run `make help`** to see all available targets.

---

## Git Branching Strategy

- **Base branch**: `develop` (not `main`)
- **Feature branches**: `feature/{issue-number}-{description}` → merge to `develop`
- **PRs**: Always target `develop`

---

## Railway CLI

**Remote commands**: `railway ssh -s <service> <command>` — NOT `railway run` (that's local-only with env vars).

---

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

**Use `AskUserQuestion` tool** when presenting options or seeking decisions.

**Rationale**: These decisions shape the codebase long-term. User should make informed choices, not have them silently resolved.

## ⚠️ Important: Scope Creep

When discovering issues tangential to the task (bugs, inconsistencies):
1. **Flag it** — note the discovery before acting
2. **Present options** — don't silently fix without approval
3. **Exception**: Obvious typos/syntax errors can be fixed inline

**Rationale**: Expanding scope without consent leads to unexpected changes, harder reviews, and violated trust.

## Tool Usage

### ⚠️ Important: Use JetBrains MCP for Codebase Navigation

Prefer `mcp__phpstorm__*` or `mcp__intellij__*` for **read-only** operations (search, symbols, file reads). Use standard `Bash`/`Write`/`Edit` for execution and file changes.

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

When working on a GitHub issue with an associated plan document, maintain an implementation log at `.ai/implementation-logs/issue-{number}-{description}.md`.

**Key practices:**
- Create the log when starting work on a non-trivial feature
- Update the decision log as decisions are made, not after the fact
- Keep entries terse (bullet points, not prose)
- Read existing implementation logs at the start of conversations to restore context
- Use the PR Notes section to draft the PR description before creating the PR

See `.ai/implementation-logs/CLAUDE.md` for the full template and guidelines.

## Git Workflow

**Proactive commits enabled.** Claude commits and pushes after each logical change. User approves via command approval UI.

### Commit Sequence
1. Verify current branch ≠ `main` or `develop`
2. Commit with Conventional Commit message (git hooks run lint automatically)
3. Push immediately (git hooks run tests automatically)
4. Run `.claude/scripts/refresh-ide.sh` (refreshes JetBrains git panel)

**Conventional Commits:** `type(scope): description`
**Types:** `feat`, `fix`, `refactor`, `test`, `docs`, `chore`, `perf`, `ci`

### Error Recovery

| Scenario | Action |
|----------|--------|
| Pre-commit hook fails | Fix issue, retry commit |
| Pre-push hook fails | Fix, amend commit (local-only), retry push |
| On protected branch | Report error, await instruction |
| Merge conflict | Report to user, await instruction |

**Amending:** ✅ Local-only commits | ❌ Never amend pushed commits

### PR Creation
When work is complete, check for existing PR on branch (`gh pr view`). If PR exists, report URL instead of offering to create.

If no PR exists, ask user via `AskUserQuestion`:
- **Create immediately** — work reviewed along the way
- **Complete checklist first** — verify against issue/plan/comments (as applicable)
- **Don't create PR** — more work needed or user will handle

**Always include issue reference** in PR description: `Closes #123` (or `Fixes #123` for bugs).

After creation, poll CI (up to 10 min) and report pass/fail with PR URL.

### Merge Strategy
- **Feature → develop**: Squash and merge (one commit per feature)
- **Develop → main**: Merge commit (preserves feature commits)

### Safety Rules
- ❌ Never force push (`--force`, `--force-with-lease`)
- ❌ Never rebase shared branches
- ❌ Never push to `main` or `develop`
- ❌ No `Co-Authored-By` trailers
- ✅ Use `git mv` for renames (preserves history)

### Branch Management
User creates and switches branches. Claude works on current branch only.

## Development Environment

**PHP**: Native PHP 8.4 via Homebrew (not Docker/Sail)
**Database**: Supabase PostgreSQL (local dev) or Docker PostgreSQL (CI)
**Services**: Redis in Docker
**Octane**: Swoole (matches production)

### Quick Reference
```bash
make supabase-reset               # Full Supabase reset with test users
make supabase-seed-users          # Seed test users only
make redis                        # Start Redis (Docker)
php artisan migrate               # Run migrations
php artisan octane:start --watch  # Dev server with hot reload
make test-quick                   # Run unit tests (~5s, no external deps)
make test                         # Run all tests (unit + integration)
make lint                         # Run linters
```

### Queue Processing

**Queue listener runs automatically** via the `Queue` run configuration (see `.run/Queue.run.xml`). Do NOT manually run queue workers.

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
6. **Database**: Use `DatabaseGateway`, never `DB::` facade

### Spatie LaravelData

❌ **NOT in Domain** (must stay framework-independent). Use in Application (response DTOs) and Infrastructure (API parsing). See `app/Infrastructure/CLAUDE.md`.

### Interface Placement

**Core Principle:** Interfaces live where they're USED, not where they're IMPLEMENTED.

- Application defines cross-layer contracts: `Application/Contracts/MixpanelClientInterface`
- Infrastructure implements: `MixpanelClient implements MixpanelClientInterface`
- Infrastructure may have internal-only interfaces (not crossing layer boundaries)
- Cross-layer interfaces in `/Contracts/` subdirectories within Domain or Application

## Key Architectural Decisions

1. **Cache-first**: Default to caching, remove only when needed
2. **Queue everything**: Webhooks respond immediately, process async
3. **Supabase shared**: Same PostgreSQL database as Next.js frontend
4. **Production uses Octane**: We run long-running daemon processes (Laravel Octane) in production. Be cautious with date/time calculations in queue jobs—always calculate timestamps in `handle()` method, not in constructor, to ensure fresh evaluations on each execution (not stale values from job creation time).
5. **Enforce over warn**: When reviewing security controls, prefer enforcement (fail-fast) over warnings. If a security boundary can be enforced, do that instead of logging a warning that might be ignored.

### Common Pitfalls

**Date Range Windows**: Never use `subMonths()` directly for backfill/sync windows—creates gaps at month boundaries. Use `startOfMonth()->subMonths()` instead. See [`.ai/docs/guides/critical-pitfalls.md`](.ai/docs/guides/critical-pitfalls.md).

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

### PHP 8.4 Features
- **Property Hooks**: Use where appropriate (getters/setters on properties)
- **Asymmetric Visibility**: Use `public private(set)` for read-only properties
- **Array Functions**: Use `array_find()`, `array_find_key()`, `array_any()`, `array_all()`
- **Static Functions**: Always use static methods and closures for pure/stateless operations (transformations, utilities, factories). Never use static properties for state—Octane persists them across requests.
- **Readonly Classes**: Mark classes as `readonly` when all properties are immutable (DTOs, value objects, transformers)
- **Import All Classes**: All classes must be imported with `use` statements—including in docblocks (`@throws`, `@param`, `@return`)
- **@throws Propagation**: Implementations must copy `@throws` from interface and any called methods

### Assertion & Validation

- **External data** → Laravel Validator (always active in production)
- **Internal contracts** → `webmozart/assert` (zero cost in production)
- **Type narrowing** → PHPStan annotations

See [`.ai/docs/guides/assertion-validation-reference.md`](.ai/docs/guides/assertion-validation-reference.md) for full reference.

## Build System: Makefile vs Composer

**Single Source of Truth**: Makefile owns all build/test/quality command implementations. The `composer.json` scripts delegate to Makefile targets.

**Run commands via Make**: `make lint`, `make test`, etc. Run `make help` for all targets.

## Code Quality & Linting

**CRITICAL**: We maintain strict code quality standards with four linters + mutation testing.

### Linters Configured
1. **Laravel Pint** (Code Style) - PER (PHP Evolving Recommendation) preset with strict rules
2. **PHPStan Level max** (Static Analysis) - Maximum strictness + 11 ShipMonk rules + bleeding edge
3. **PHP Insights** (Architecture/Quality) - Complexity, architecture, code quality metrics
4. **PHPArkitect** (Architecture Enforcement) - Clean Architecture layer boundaries + naming conventions
5. **Deptrac** (Layer Dependencies) - Analyzes `use` statement imports for CA violations
6. **Infection** (Mutation Testing) - Validates test quality by catching weak assertions (especially AI-generated tests)

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

### Pint Style Fixes

**ALWAYS try `make fix` before manually editing for style issues.** Pint auto-fixes ~95% of style issues.

**Git Hooks**: Pre-commit runs Pint + PHPStan + PHPArkitect. Pre-push runs Pest + PHP Insights + PHPArkitect.

### PHPArkitect Naming Conventions

Enforced by `phparkitect.php` (layer dependencies defined in Clean Architecture above):

- Controllers → `*Controller`
- Application services → `*UseCase` or `*Service`
- Repositories → `*Repository`
- API clients → `*Client`

### Testing

**⚠️ Read `tests/TestingStrategy.md` first** — defines what to test per layer, avoiding wasted effort.

**Consider `zen:testgen` MCP** for complex test suites or when you want a second opinion on edge cases. See `tests/CLAUDE.md` for mutation testing workflow.

### ⚠️ IMPORTANT: Bypassing Linters

**NEVER bypass linting rules** (`@phpstan-ignore`, `@psalm-suppress`, baseline files, etc.) **without explicit user approval.**

If a linter reports an issue, fix the code—don't suppress it. Only bypass when:
1. User explicitly approves
2. Known false positive in framework/package (document why)
3. Temporary external dependency issue (add TODO)

### 📖 Stubborn Linting Issues

**When encountering persistent linting errors**, consult [`.ai/docs/guides/common-linting-errors.md`](.ai/docs/guides/common-linting-errors.md) for ranked solutions. This guide covers:
- `shipmonk.checkedExceptionInCallable` — checked exceptions in closures
- `missingType.checkedException` — false positives with `@param-immediately-invoked-callable`
- `shipmonk.nonNormalizedType` — parent/child exception hierarchies in `@throws`

**Always check this guide before:**
- Using `@phpstan-ignore` annotations
- Adding entries to `phpstan.neon` ignoreErrors
- Asking the user how to resolve a linting error

---

*See README.md for project overview and setup. See tests/CLAUDE.md for testing guidance.*
