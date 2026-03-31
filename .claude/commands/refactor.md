---
description: "Safe behavioral-preserving refactor with implementation log and deviation tracking"
allowed-tools: Bash(git *), Bash(make *), mcp__sequential-thinking__sequentialthinking, mcp__phpstorm__*, mcp__webstorm__*, mcp__intellij__*, Read, Grep, Glob, Edit, Write, AskUserQuestion, TaskCreate, TaskUpdate, TaskList, TaskGet
---

# Refactor Command

You are performing a **pure refactor**. Zero behavioral changes. No improvements, no cleanup, no "while we're here" fixes.

Argument: `$ARGUMENTS` — issue number, file path, or description of refactor scope.

**Do NOT use subagents.** They lose context, compare against imagined code, and take shortcuts. All work happens in this conversation.

## Behavioral Preservation

These MUST NOT change. Every one has caused a real defect in past refactors:

- **Exception messages** — copy verbatim. Never shorten or rephrase (affects Sentry fingerprinting)
- **Exception types** — same type thrown from same scope (don't swap `ErrorException` for `LogicException`)
- **Exception timing** — if validation happened at call-time, don't shift it to construction-time without flagging as a deviation
- **@throws docblocks** — must match actual post-refactor behavior
- **Log messages** — copy verbatim including interpolation and context arrays
- **Return values** — same type, nullability, shape, edge-case behavior
- **Control flow** — no new guards, assertions, defensive checks, or early-returns (e.g. don't add `LogicException` guards for "impossible" states)
- **Side-effect order** — log-before-throw, cache-before-return, etc. stays as-is
- **New files** — must implement exactly what was inline, nothing more. Migrations must produce SQL identical to original inline queries. Service provider `provides()` must list every abstract registered.

**If a behavioral change is unavoidable** (e.g. extracting a class inherently shifts DI timing): STOP. Present before/after behavior to the user via `AskUserQuestion` with your recommendation. Log the decision as a deviation.

## Read Before Write

Before modifying ANY method: read the complete original on disk. After modifying: `git diff` the file to verify the extraction is behaviorally identical. If revisiting a file already changed in this session, use `git show <base-branch>:<path>` to compare against the true pre-refactor baseline.

## Plan Adherence

If a plan document exists (`.ai/plans/`), follow it exactly — schema names, class names, file locations, approach. If deviating: **STOP**, log the deviation and justification in the implementation log BEFORE proceeding.

If no plan exists, define scope from the argument and proceed. Create an implementation log if the scope is non-trivial (3+ files).

## Test Changes

Test **assertions and expectations must not change**. Tests may only change to accommodate new constructor signatures or renamed/moved classes. If you're changing what a test asserts, you changed behavior — stop and check.

## Process

### Step 1 — Load Context

1. Find and read the plan document (`.ai/plans/` matching the issue), if one exists
2. Find or create the implementation log (`.ai/implementation-logs/`, follow template in `.ai/implementation-logs/CLAUDE.md`)
3. If resuming: read the implementation log to understand current state
4. Break scope into sections of ~5-10 files, grouped by module or dependency order

### Step 2 — Work Loop

For each section:

1. **Implement** — modify the files, following all rules above
2. **Verify** — `git diff` each changed file. Check: same exceptions? Same messages? Same timing? Same flow? Any new throw/catch/return paths?
3. **Update implementation log** — record what was done, any deviations with justification
4. **Checkpoint** (large refactors only, 10+ files) — run `make lint` and `make test-quick` between sections
5. **Context management** — after updating the implementation log, suggest the user run `/compact` if context is growing large. The log preserves all critical state for resumption.

### Step 3 — Completion Summary

After all sections, produce:

```
## Refactor Summary

### Scope
[count] files across [areas]

### Deviations from Plan
| # | Plan Said | Actual | Justification |
|---|-----------|--------|---------------|
(NONE if clean)

### Behavioral Changes
NONE — or list any user-approved changes with the decision context

### Remaining Known Issues
[Any items skipped, blocked, or deferred]
```

Stop hooks will run `make fix`, `make lint`, and `make test` automatically on completion.
