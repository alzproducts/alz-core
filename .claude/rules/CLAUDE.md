# Rule Authoring

Files here are **path-scoped rules**. Each file auto-loads when Claude opens a file matching its `paths:` frontmatter glob — avoiding the cost of always-on `CLAUDE.md` content.

## Rules vs CLAUDE.md

- **Scoped rules prevent local mistakes** — wrong boilerplate, missing interface, inlining work the gateway already handles. Shape-of-this-file problems that only bite when Claude is editing that file type.
- **CLAUDE.md prevents global misunderstandings** — architecture, layer responsibilities, forbidden operations, safety-critical conventions (destructive commands, Octane safety, DB-facade bans) that must fire regardless of which file is open.

If the guidance is only actionable when editing a specific file type, it's a scoped rule. If it shapes Claude's mental model of the codebase or must fire on every action, it belongs in `CLAUDE.md`.

## Frontmatter

```yaml
---
paths:
  - "app/Infrastructure/**/*Repository.php"
  - "!app/Infrastructure/**/Models/*ViewModel.php"   # ! negates
---
```

## Authoring Principles

- **Declarative.** Every bullet is a directive: DO X, DO NOT Y, EXCEPTION Z. Not narrative, not tutorial.
- **Prefer DO over DO NOT.** Positive form tells the reader what shape to write, not just what to avoid. Use DO NOT only when the temptation to do the wrong thing is real.
- **Self-contained.** Assume the reader sees the file they're editing, has autocomplete on injected services, and is extending a class whose abstract methods the compiler will enforce. Don't restate that surface.
- **Name your exceptions.** A rule with a named exception + canonical class pointer stays accurate as the codebase grows. A rule with none drifts.

## Do Not Include

- **Anything the linter catches.** PHPStan, PHPArkitect, Pint, and ShipMonk already flag mechanical issues — missing generics, wrong return types, `@throws` ordering. If the mistake is local to the file, the linter is enough. **EXCEPTION:** patterns whose omission cascades across layers (Domain → Application → Infrastructure → Presentation) earn a rule regardless — discovering it late means reworking the whole feature branch.
- **Inventories.** Don't list every method on a gateway Claude is calling — "check the gateway first" is enough.
- **Examples for self-explanatory rules.** If the rule is "spread the mapper output with the upsert key", Claude knows what spread is.
- **Discoverable pairings.** If a ViewModel sits next to its write Model, the directory already shows the pairing.

## Keep

- **The "why"** when the rule doesn't explain itself — hidden constraint, real prior bug, non-obvious compliance reason. One sentence.
- **Canonical class pointers.** `Canonical: EloquentSyncCursorRepository` resolves ambiguity more cheaply than an inline example.

## Trimming Test

Remove a bullet. If Claude still writes compliant code without it, the bullet was noise.
