---
description: Post-implementation quality sweep — review and fix common issues before human review
allowed-tools: Bash(git *), Bash(make *), mcp__sequential-thinking__sequentialthinking, mcp__phpstorm__*, mcp__webstorm__*, mcp__intellij__*, Read, Grep, Glob, Edit, Write, AskUserQuestion, TaskCreate, TaskUpdate, TaskList, TaskGet
---

# Post-Implementation Quality Sweep

Use ultrathink and the mcp__sequential-thinking__sequentialthinking tool throughout this review.

Review and fix common issues that get missed during implementation. This is a tidy-up pass before human review — not a full audit.

## Scope Discipline

**Stay strictly within the review checklist below.** If you encounter bugs, inconsistencies, or improvements outside the scope of this checklist, **do not fix them**. Note them for the summary section and move on.

If any individual check is blocked by complications or ambiguity, **skip it** — log your reasoning and all relevant context in the summary, then continue with the next check. Never abandon the sweep because of a single issue.

## Scope Detection

1. Check for uncommitted changes (`git diff` and `git status`)
2. If uncommitted changes exist → review those files
3. If no uncommitted changes → identify the base branch and review all changes on the current feature branch (`git diff <base>...HEAD`)

## How to Apply Fixes

- **Reference existing code** in the codebase as the canonical example for how fixes should be applied. Do not invent patterns.
- Use `TaskCreate` to build a checklist from the review items below, and `TaskUpdate` to track progress as you work through them.

---

## Review Checklist

### Presentation Layer

- **Thin controllers** — Controllers should delegate to Application layer use cases. No business logic in controllers.

### Application Layer

- **Feature sub-namespaces** — Is this feature complex enough to warrant its own sub-namespace? (e.g., `Application/ContactSubmission`, `Application/ShopWired/PricingUpdate`). Check similar features for precedent.
- **`@throws` propagation** — Every interface and its concrete implementation must declare `@throws` tags for all exceptions they may throw. Carefully trace through the call chain to ensure complete propagation.

#### Use Cases

- **No untyped data arrays** — If keyed arrays with PHPStan annotations are used internally instead of typed PHP classes, extract proper classes.
- **Business logging** — Every use case must inject `LoggerInterface` and log business milestones at `info` level. At minimum: entry (what operation, key identifiers/context) and exit (outcome/result summary). Log all code paths — early returns, no-op conditions, and error recovery should all produce a log entry so operators can trace what happened. Use structured context arrays with snake_case keys, never string interpolation in log messages. Reference existing use cases (e.g., `UpdateSkuUseCase`, `SyncProductsUseCase`) for the established pattern.
- **Clarity and simplicity** — Any moderately complex use case likely needs refactoring. Can complex logic be extracted into private methods? Can code be moved outside the use case where appropriate — e.g., static factories on domain objects, dedicated mappers for complex transformations, domain value objects? Only create these if justified, but usually they can be.
- **Static factories** — DTOs and value objects with derivable fields should use named static factories rather than exposing all constructor parameters. Check for constructors where one or more parameters can be computed from the others.
- **Note:** Use cases are typically the most complex part of a feature. Make small, obvious improvements during this sweep (extracting a private method, moving a factory). For anything larger, skip it — provide recommendations in the summary and defer to a focused, collaborative refactoring session with the user.

#### Jobs & Listeners

- **Pattern consistency** — Compare against existing jobs in the codebase; most follow a very similar structure.
- **Thin dispatch** — Jobs should dispatch to a use case with minimal surrounding logic.
- **Queue configuration** — Verify queue name, retry attempts, and backoff are appropriate for the job's workload.
- **No redundant logging** — Jobs should NOT log "starting" (Queue::before handles this) or completion results that the UseCase already logs. Only keep job-level logs that add unique context not available elsewhere (e.g., input parameters like date ranges, sync modes). If a job is returning result data just to log it, move that logging to the UseCase.
- **Listeners must be thin** — Listeners should only perform simple logic checks, basic DB reads, and dispatch jobs. Any listener that calls an external API, performs heavy computation, or does complex multi-step work should instead dispatch a queued job to handle that work. This gives us retry/backoff resilience and keeps listeners fast. Compare against existing listeners for precedent.

### Infrastructure Layer

#### Exception Handling

- **Catch, log, translate** — All infrastructure exceptions from code we don't control (third-party SDKs, API calls) must be caught, logged with context, and translated to domain exceptions.
- **Failure paths throw** — Failure conditions must throw domain exceptions, not return silently.
- **Preserve context** — Pass all relevant information up the chain. For batch operations, consider returning a result object instead of throwing on first failure.

#### API Client Methods

- **Typed parameters** — Client methods should accept domain objects, Commands, or DTOs — not raw primitives/arrays. Place the type in Domain if it fits; otherwise Infrastructure-level is fine. Exception: trivial single-scalar calls (e.g., delete by ID) are acceptable as-is.
- **Domain types over primitives** — Use domain value objects (`Money`, `SKU`, `IntId`, `Guid`, etc.) instead of raw `string`/`int`/`float` where applicable.

#### Other

- **Logging** — Infrastructure should log SDK/technical details before translating exceptions. Not excessive, but enough to trace issues. Do not log data that higher layers will log (avoid duplication across layers).

### Domain Layer

- **Domain types** — Use project-specific types where appropriate: `SKU`, `IntId`, `Money`, etc.
- **Native exception handling** — For PHP/native exceptions, search the domain layer for existing patterns showing how they are handled. Usually we catch and rethrow as domain exceptions, but there may be other established approaches (e.g., wrapping via Carbon for date handling). If no existing pattern is found, log it as something to discuss with the user.

### General

- **Code placement** — Is code in the correct architectural layer? Are feature sub-namespaces used consistently with similar features nearby? Compare against the structure of similar features in each layer.
- **Logging at the right layer** — Each layer should only log what it uniquely knows. Data should not be passed across layers just to be logged elsewhere. If a result is returned from a UseCase to a Job solely for logging, move the logging into the UseCase. Infrastructure logs SDK/technical details, Application logs business milestones/results, Presentation (jobs/controllers) logs only delivery-specific context (input parameters, queue metadata).
- **No false nullables** — Review new/changed classes (VOs, DTOs, events, enums) for properties declared as `?Type $field = null` that are actually always present at construction time. If every caller passes a value, the property should be required (non-nullable, no default). Nullable defaults are only appropriate when the field is genuinely optional. This is especially common in newly created classes where `= null` was added for convenience during development.
- **Linting bypasses** — Scan changed files for `@phpstan-ignore`, `@psalm-suppress`, baseline additions, or similar suppression annotations. Each must have explicit user approval and a documented justification. Check `.ai/docs/guides/common-linting-errors.md` for ranked alternatives before accepting any bypass. Compare against existing bypasses in the codebase for precedent.

### Testing

- **If tests were created** — verify they follow `tests/TestingStrategy.md` and `tests/CLAUDE.md`.

---

## Process

### Step 1: Review
Perform all checks above against the detected scope. Fix issues as you find them.

### Step 2: Lint
Run `make fix` then `make lint`. Fix any failures.

### Step 3: Test
Run `make test`. Fix any failures.

### Step 4: Second Pass
Did linting or test fixes surface new issues? For example, PHPStan catching missing checked exceptions may require job exception handling to be revisited. If so, repeat the relevant review items.

### Step 5: Summary
Present a summary to the user covering:

1. **Issues found and fixed** — Brief description of each change made
2. **Out-of-scope observations** — Errors, inconsistencies, or improvements noticed during review that fall outside this sweep's scope
3. **Unresolved complications** — Changes that couldn't be made due to ambiguity or valid reasons to deviate from guidelines, along with your reasoning

If you have recommended solutions for any unresolved items, present them to the user using the `AskUserQuestion` tool.
