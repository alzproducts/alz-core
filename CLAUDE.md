# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

**Architecture guide**: [`.ai/docs/architecture-overview.md`](.ai/docs/architecture-overview.md) — high-level system context, deployment topology, layer diagrams, and key data flows. Use as orientation only; always verify details against the code.

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

## Railway CLI

**⚠️ Production only — never use unless the user explicitly requests a production operation.**

`railway ssh -s alz-core-worker <command>` runs commands on the **production** worker. Only use when the user specifically asks to run something in prod.

Note: `railway run` is local-only with env vars, not remote execution.

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
- Changes to custom PHPStan rules, PHPArkitect rules, or Deptrac config

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

**Branching:** Base = `develop` (not `main`) | Features = `feature/{issue}-{desc}` | PRs → `develop`

**Proactive commits enabled.** Claude commits/pushes after logical changes. User approves via UI.

**Commit format:** `type(scope): description`
Types: `feat` | `fix` | `refactor` | `test` | `docs` | `chore` | `perf` | `ci`

| Scenario | Action |
|----------|--------|
| Pre-commit hook fails | Fix issue, retry commit |
| Pre-push hook fails | Check Redis is running (`make redis`), then fix, amend (local-only), retry push |
| On protected branch | Report error, await instruction |
| Merge conflict | Report to user, await instruction |

**PR Creation:** Always use the `/pr` skill (never raw `gh pr create`). It handles pre-flight checks, commit analysis, issue linking, and CI monitoring.

**Merge:** Feature→develop = squash | Develop→main = merge commit

**Safety:**
- ❌ Force push, rebase shared branches, push to main/develop, `Co-Authored-By` trailers
- ✅ Use `git mv` for renames (preserves history)

**Branch management:** User creates/switches branches. Claude works on current branch only.

## Development Environment

**PHP**: Native PHP 8.4 via Homebrew (not Docker/Sail)
**Database**: Supabase PostgreSQL (local dev) or Docker PostgreSQL (CI)
**Services**: Redis in Docker
**Octane**: Swoole (matches production)

### Quick Reference
```bash
make db-reset-full                # Full DB reset (Supabase + Laravel migrations) — see database/CLAUDE.md
make supabase-seed-users          # Seed test users only (no DB reset)
make redis                        # Start Redis (Docker)
php artisan migrate               # Run migrations
php artisan octane:start --watch  # Dev server with hot reload
make test-quick                   # Run unit tests (~5s, no external deps)
make test                         # Run all tests (unit + integration)
make lint                         # Run linters
```

### Local API Testing

Consumer API endpoints use an `X-Local-Bypass` header instead of a JWT (local only, from `127.0.0.1`):
- Set `SUPABASE_LOCAL_BYPASS_SECRET` + `SUPABASE_LOCAL_TEST_EMAIL` in `.env`
- Send `X-Local-Bypass: <secret>` header — see `ValidateSupabaseJwtMiddleware`
- The bypass secret is available as `$API_BYPASS_SECRET` env var (set in `.claude/settings.local.json`)

### Debugging & Logs

| Log | Location | Contains |
|-----|----------|----------|
| Laravel | `storage/logs/laravel.log` | Application-level logs (use cases, jobs, exceptions) |
| Octane | `storage/logs/octane.log` | Request-level output (status codes, auth rejections, startup errors) |

**Check Octane log first** when API requests fail — middleware rejections (401, 403) don't reach Laravel's logger.

### Queue Processing

**Queue listener runs automatically** via the `Queue` run configuration (see `.run/Queue.run.xml`). Do NOT manually run queue workers.

### Smoke Testing Jobs Locally

Dispatch jobs locally via tinker — they run on the local queue worker:
```bash
php artisan tinker --execute="SomeJob::dispatch();"
```
Check `storage/logs/laravel.log` for job output. Never dispatch to production for smoke tests.

---

## Clean Architecture

This project follows **Clean Architecture** (Robert C. Martin) — dependencies point inward, outer layers depend on inner layers, never the reverse.

### Layers (Outer → Inner)

- **Presentation** (`App\Presentation`) — Entry points: HTTP controllers, console commands. Delegates to Application layer. *Naming: `*Controller`*

- **Infrastructure** (`App\Infrastructure`) — External world: API clients, database repositories, SDK wrappers, queue jobs. Implements Domain interfaces. Validates external data with exceptions. Jobs are delivery mechanisms (like controllers for HTTP). *Naming: `*Client`, `*Repository`, `*Job`*

- **Application** (`App\Application`) — Use cases: orchestrates Domain objects and Infrastructure services to accomplish tasks. Dispatches async work via dispatcher interfaces (not job classes directly). *Naming: `*UseCase`, `*Service`*

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

### Creating Exceptions

**Always search for existing exceptions before creating new ones.** Search `app/Domain/Exceptions` for similar patterns. Prefer reusing/extending existing exceptions over proliferating new ones.

- **Static messages**: Exception messages must be static (no interpolated dynamic data). Pass dynamic data as readonly constructor properties and return them from `context()` for Sentry grouping.

## Modern PHP Standards

**Target**: PHP 8.4+ features and best practices

### PHP 8.4 Features
- **Property Hooks**: Prefer for computed/derived getters and validated setters. Keeps logic co-located with the property instead of scattered in methods.
- **Asymmetric Visibility**: Use `public private(set)` for read-only properties
- **Array Functions**: Use `array_find()`, `array_find_key()`, `array_any()`, `array_all()`
- **Static Functions**: Always use static methods and closures for pure/stateless operations (transformations, utilities, factories). Never use static properties for state—Octane persists them across requests.
- **Readonly Classes**: Mark classes as `readonly` when all properties are immutable (DTOs, value objects, transformers). **Exception**: classes needing property hooks cannot be `readonly` — use per-property `readonly` on the non-hooked properties instead.
- **Import All Classes**: All classes must be imported with `use` statements—including in docblocks (`@throws`, `@param`, `@return`)
- **@throws Propagation**: Implementations must copy `@throws` from interface and any called methods

### Assertion & Validation

- **External data** → Laravel Validator (always active in production)
- **Internal contracts** → `webmozart/assert` (zero cost in production)
- **Type narrowing** → PHPStan annotations (never use assertions as PHPStan workarounds)

See [`.ai/docs/guides/assertion-validation-reference.md`](.ai/docs/guides/assertion-validation-reference.md) for full reference.

## Build System: Makefile vs Composer

**Single Source of Truth**: Makefile owns all build/test/quality command implementations. The `composer.json` scripts delegate to Makefile targets.

**Run commands via Make**: `make lint`, `make test`, etc. Run `make help` for all targets.

## Code Quality & Linting

**CRITICAL**: We maintain strict code quality standards with five linters + mutation testing.

### Linters Configured
1. **Laravel Pint** (Code Style) - PER (PHP Evolving Recommendation) preset with strict rules
2. **PHPStan Level max** (Static Analysis) - Maximum strictness + 11 ShipMonk rules + bleeding edge
3. **PHPArkitect** (Architecture Enforcement) - Clean Architecture layer boundaries + naming conventions
4. **Deptrac** (Layer Dependencies) - Analyzes `use` statement imports for CA violations
5. **Infection** (Mutation Testing) - Non-blocking CI on PRs to main (develop→main promotion)

### Running Linters

**Primary commands** (run `make help` for full list):

```bash
make fix          # Auto-fix code style with Pint
make lint         # Pre-commit: Pint + PHPStan + PHPArkitect + Deptrac + TLint (~7s)
make lint-full    # Full: Pint + PHPStan + PHPArkitect + Deptrac + TLint + Psalm
make deptrac      # Run Deptrac layer dependency analysis
make check        # Full validation: lint-full + tests
make test-ai      # Validate AI-generated tests (test + pest mutate)
```

### Pint Style Fixes

**ALWAYS try `make fix` before manually editing for style issues.** Pint auto-fixes ~95% of style issues.

**Git Hooks**: Pre-commit runs Pint + PHPStan + PHPArkitect. Pre-push runs Pest + Deptrac + TLint.

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

### Complexity Baseline (`phpstan-complexity-baseline.neon`)

**Only update existing entries** when line counts shift (e.g., adding an import changes surrounding classes). **NEVER add new baseline entries for new code** — instead, decompose the code to fit within limits (see `app/Application/CLAUDE.md` → Use Case Decomposition).

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
