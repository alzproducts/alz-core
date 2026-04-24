# Implementation Log: #643 — Migrate Presentation layer conventions to path-scoped claude rules

## Issue Context

Extract file-type-specific conventions from `app/Presentation/CLAUDE.md` into `.claude/rules/` files
with `paths:` frontmatter globs — the same migration pattern applied to Eloquent rules in #642.

Rules auto-load only when Claude edits a matching file type, eliminating irrelevant context when
working on unrelated parts of the layer.

## Implementation

### Files created

- `.claude/rules/presentation-controllers.md` — scoped to `Http/**/*Controller.php`
  - Exception handling: no try-catch (global `InternalApiExceptionMapper` handles it)
  - Class shape: `final readonly`, single-action invokables preferred
  - Request parsing: Spatie Data DTOs over FormRequests

- `.claude/rules/presentation-commands.md` — scoped to `Console/Commands/**/*.php`
  - Exit codes: always `self::SUCCESS` / `self::FAILURE`
  - Exception handling: catch for user-friendly output + resolution hint
  - Anti-patterns: no rethrow, no catch-to-log

### Files modified

- `app/Presentation/CLAUDE.md` — stripped controller/command sections that are now in rules;
  retained layer-wide context (purpose, jobs placement, directory org, cross-cutting anti-patterns)

## Test Results

No PHP source files changed — rules files are Markdown only. `make test` would pass trivially.

## Lint Results

No PHP source files changed — no linter errors expected.

## Handoff Notes

- Two rules files cover the two distinct file-type shapes in the Presentation layer
- `InternalApiExceptionMapper` is named as canonical in the controller rules — it's the class
  Claude would otherwise think to work around with per-controller try-catch
- `app/Presentation/CLAUDE.md` retains Directory Organization (applies to all file types) and
  the layer purpose/jobs-placement note (relevant regardless of which file is open)
