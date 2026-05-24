---
name: validate-domain
description: Validate a Value Object or Domain class against the project's placement and design quality rules. Use when the user types /validate-domain (with a class name, file path, or directory as argument), says "validate this domain class", "check if X belongs in Domain", "is this a well-shaped VO", or wants a Clean-Architecture audit of a Domain-layer class.
---

# Validate Domain

Apply the project's VO placement and design rules to a target class or directory.

## Quick start

The argument is what needs validating: a class name, file path, or directory. An optional flag controls stop-at-A behaviour.

Examples:
- `/validate-domain Money` — searches the codebase for the class
- `/validate-domain app/Domain/Catalog/Product/ValueObjects/Sku.php` — direct file
- `/validate-domain app/Domain/Shared/Money` — directory (validate every VO within)
- `/validate-domain app/Domain/Shared/Money --include-design` — also runs Section B on files that fail A

Flags:
- `--include-design` — run Section B even on files where Section A fails. Default: skip B for those files (placement decided first).

If no argument is given, ask via AskUserQuestion which class or directory to validate.

## Workflow

1. **Resolve target.**
   - Class name → use `mcp__phpstorm__search_symbol` or Glob for `**/{ArgName}.php`
   - File path → Read directly
   - Directory → Glob for `**/*.php` under it; focus on classes under `ValueObjects/` or single-purpose data classes
   - Multiple files → validate each, group findings per file

2. **Read each target file.**

3. **Apply Section A (Placement)** — does it belong in Domain? See [RULES.md](RULES.md).
   - Each rule = binary pass/fail.
   - On failure, use the redirect map to recommend the correct layer.

4. **Apply Section B (Design Quality).**
   - **If Section A passed for this file**: run all B rules. Each rule = binary pass/fail. On failure, recommend in-place redesign (no layer move).
   - **If Section A failed for this file**: skip B by default and emit the line `B skipped — placement decided first. Re-run with --include-design for design feedback anyway.` If `--include-design` was set on this invocation, run B as normal.

5. **Apply Layered VOs section** — only if the class participates in a strictness hierarchy (e.g. `Email → StrictEmail`).

6. **Categorise findings by severity:**
   - **CRITICAL**: rule clearly broken, visibly wrong code
   - **HIGH**: rule broken with ambiguity, or significant design risk
   - **MEDIUM**: phrasing/naming concerns, soft-spec violations
   - **LOW**: nitpicks, minor style

7. **Triage:**
   - **Auto-fix** (no prompt): dead imports, obvious typos in docblocks, missing `final readonly` when the class is plainly immutable, mechanical phrasing fixes with one correct resolution.
   - **Ask first** (AskUserQuestion, batched): layer placement moves, splitting a VO into strictness types, removing validator-as-method patterns, anything touching the public API of the class.

8. **Apply approved fixes via Edit.**

9. **Phase 6 summary** — list what was fixed (file + one-line change) and what remains unresolved. Keep scannable, no prose.

## Report format

**Defects only** — do not enumerate rules that passed. Anything not listed has passed.

**Per-file blocks.** When the argument resolves to multiple files, each file gets its own block:

```
=== path/to/File.php ===
[Section A defects, by severity]
[Section B defects, by severity OR the "B skipped" line]
```

**Multi-file roll-up.** End the report with a one-line-per-file summary showing defect counts:

```
- app/Domain/Foo.php: 0 defects
- app/Domain/Bar.php: A1 (placement), B skipped
- app/Domain/Baz.php: 2 B defects
```

Files with `0 defects` are passing the whole ruleset. Files with `B skipped` failed Section A and were not evaluated for design quality (use `--include-design` to override).

## Notes

- Use JetBrains MCP tools for navigation (`mcp__phpstorm__search_symbol`, `mcp__phpstorm__search_in_files_by_text`) when the project name matches the working directory.
- Do not validate test files — point them at the source they test instead.
- Do not run this skill on classes outside `app/Domain/` — its rules are Domain-specific. If the target is in Application/Infrastructure/Presentation, report that and stop.
- Do not commit changes — follow project policy (commits only when the user explicitly asks).

## Rules

Full ruleset: [RULES.md](RULES.md).

Sections:
- **A — Placement** (5 rules) — failure → move to Application/Infrastructure/Presentation
- **B — Design Quality** (4 rules) — failure → redesign in place
- **Layered VOs** — applies only to strictness-stacked variants
- **Related rules** — pointer to path-scoped rules that own adjacent concerns (class placement, exceptions, validators)
