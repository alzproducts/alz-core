# Application Layer — Scoped Rules Migration (#649)

## Context

`app/Application/CLAUDE.md` currently mixes architectural guidance with a handful of tight file-shape conventions. This issue ports only the tightly-focused conventions into path-scoped `.claude/rules/` files so they load on matching globs rather than on every file edit. Content that is layer-wide, about directory creation, or only applies to a narrow subset of files stays in `CLAUDE.md` — per `.claude/rules/CLAUDE.md`:

> If the guidance is only actionable when editing a specific file type, it's a scoped rule. If it shapes Claude's mental model of the codebase or must fire on every action, it belongs in `CLAUDE.md`.

A rule earns a scoped-rule file only when:
1. It fires when editing a specific, globbable file type, and
2. It shapes lines or structure local to that file.

Anything that fails either test stays in `CLAUDE.md`.

## Section-by-Section Classification

The issue's "Existing Content to Port" list was the starting universe. Applying the tight-focus test narrows it:

| Section | Ports to scoped rule? | Reason |
|---|---|---|
| Directory Structure for New Integrations | **No** | Guidance about directory creation, not about what to type in any specific file. No matching file exists when this guidance is most useful. |
| Use Case Decomposition (250-line trigger, Transformers, Resolvers) | **Yes** — `*UseCase.php` | Governs the shape of the UseCase file: thin orchestrator, extracted helpers. Actionable while editing. |
| Async Dispatch (dispatcher interface, not job class) | **Yes** — `*UseCase.php` | Line-level — shapes the exact dispatch call Claude writes inside the file. |
| Logging (PSR-3, business events only) | **No** | Layer-wide stance (applies equally to Services), and "business events only" is philosophy not file shape. |
| Client Interface Design: Pre-Resolved Parameters | **Yes** — `*ClientInterface.php` | Shapes the parameter list of the interface. Not tight on `*Resolver.php` — Resolver shape is already covered by Decomposition. |
| Complex Use Case Reference — typed result objects | **Yes** — `*UseCase.php` | Both canonical complex UseCases (`UpdateCostPriceBySupplierUseCase`, `UpdateProductSellingPricesUseCase`) return typed `*Result` objects. Codebase norm, not Shopwired-only. Shapes the UseCase's return type + assembly lines. |
| Complex Use Case Reference — phase factory (`fromPhases`) | **No** | Shopwired-only elaboration. Rule actually shapes the `*Result.php` class, not `*UseCase.php` — glob mismatch. Keep as canonical pointer in CLAUDE.md. |
| Complex Use Case Reference — union + `match(true)` on `instanceof` | **No** | Shopwired-only micro-pattern; general PHP idiom not specific to UseCases; competent devs reach for `match(true)` naturally. |
| Complex Use Case Reference — thin `execute()` pipeline | **No — drop** | Redundant with Decomposition's "keep `execute()` pipeline thin." |

Four sections port (three from elsewhere plus typed-result-objects from Complex Use Case Reference). Three stay in `CLAUDE.md` alongside the already-staying Exception Handling and Interface Placement Principle. One bullet is dropped as redundant.

## Proposed Rule Files

Rule-authoring principles from `.claude/rules/CLAUDE.md` apply: declarative DO/DO NOT/EXCEPTION bullets, self-contained (assume the reader sees the file they're editing), named exceptions, canonical class pointers, trimming test per bullet.

### 1. `.claude/rules/application-use-cases.md`

**Paths:**
```yaml
- "app/Application/**/*UseCase.php"
```

**Absorbs from `app/Application/CLAUDE.md`:**

- **Use Case Decomposition** (current lines 105–125) — 250-line trigger; when triggered, promote to feature subdirectory, extract Transformers (pure static, no deps, partition/map/dedupe) and Resolvers (single-responsibility lookups, take only the dependency they need); keep `execute()` as a thin pipeline + side effects; avoid Services unless stateful/cross-concern. Canonical pointer: `Linnworks/UpdateCostPriceBySupplier/`.
- **Async Dispatch** (current lines 25–27) — dispatch via dispatcher interfaces (e.g., `ShopwiredSyncDispatcherInterface`), never `SomeJob::dispatch()` directly. Rationale inline: jobs are an Infrastructure delivery mechanism.
- **Typed Result Objects** (promoted from Complex Use Case Reference bullet 1) — DO return a typed `{Feature}Result` object when the UseCase reports per-item outcomes (succeeded count + typed `*Skipped` / `*Failed` entries); DO NOT return an array shape like `['failed' => [...], 'skipped' => [...]]`. Canonical: `CostPriceUpdateResult` (Linnworks), `PriceUpdateResult` (Shopwired) — both canonical complex UseCases already follow this pattern.

Reword into bullets with canonical class pointers; drop the ASCII tree diagrams where the rule survives without them.

### 2. `.claude/rules/application-client-interfaces.md`

**Paths:**
```yaml
- "app/Application/Contracts/**/*ClientInterface.php"
```

Scoped to `*ClientInterface.php` only — `*Resolver.php` dropped because the resolver's file shape (single-responsibility lookup) is already covered by Decomposition on `*UseCase.php`, and the pre-resolved-parameters rule is strictly about the interface's parameter list, not the resolver's body.

**Absorbs:**

- **Client Interface Design: Pre-Resolved Parameters** (current lines 46–58) — interface parameters are pre-resolved domain values (`Guid $supplierGuid`, `array<string, Money> $prices`), never raw names requiring the client to look up. UseCase orchestrates resolution before calling the client. Include the one-sentence "why": resolution is orchestration (business decisions — batch vs single, caching, error handling), not structural mapping.

## Trimmed `app/Application/CLAUDE.md`

Three sections move out in full (Async Dispatch, Pre-Resolved Parameters subsection, Use Case Decomposition), one section is trimmed (Complex Use Case Reference — one bullet promoted, one dropped), four stay intact (Directory Structure, Logging, Interface Placement Core Principle, Exception Handling). Post-migration structure:

```
# Application Layer

## Purpose
Orchestrates Domain logic + Infrastructure services. Entry point from
Presentation. Defines cross-layer contracts; never implements them.

## Directory Structure for New Integrations
[KEPT — current content lines 3–21 preserved; tree + "when to use each" table]

## Logging
[KEPT — current content lines 31–33 preserved; PSR-3 LoggerInterface accepted
layer-wide, business events only]

## Interface Placement
Core Principle kept (lines 35–44).
Pre-Resolved Parameters subsection REMOVED (now in scoped rule).
Interface @throws Declarations pointer (line 62) REMOVED — rolled into the new
"Per-File Conventions" section below to keep the pointer list in one place.

## Exception Handling: Default is Don't Catch
[KEPT — current content lines 66–101 preserved verbatim]

## Complex Use Case Reference
[TRIMMED — Shopwired/PricingUpdate/ pointer retained, two bullets kept (phase
factory, union + match(true)), one bullet moved to scoped rule (typed result
objects), one bullet dropped as redundant (thin execute pipeline — already
covered by Decomposition rule)]

## Per-File Conventions
See `.claude/rules/` for file-type-specific rules:
- `application-use-cases.md` — `*UseCase.php`: decomposition trigger, async dispatch
- `application-client-interfaces.md` — `*ClientInterface.php`: pre-resolved parameters
- `repository-contracts.md` — `*Repository*.php`: interface @throws declarations (existing)
```

**Content accounting** (every removed line has a home — nothing silently dropped):
- Directory Structure (lines 3–21) → **kept in CLAUDE.md**
- Async Dispatch (lines 25–27) → **moved to `application-use-cases.md`**
- Logging (lines 31–33) → **kept in CLAUDE.md**
- Interface Placement Core Principle (lines 35–44) → **kept in CLAUDE.md**
- Pre-Resolved Parameters (lines 46–58) → **moved to `application-client-interfaces.md`**
- Interface @throws pointer (line 62) → **rolled into Per-File Conventions pointer list**
- Exception Handling (lines 66–101) → **kept in CLAUDE.md**
- Use Case Decomposition (lines 105–125) → **moved to `application-use-cases.md`**
- Complex Use Case Reference (lines 127–135):
  - Typed result objects bullet → **moved to `application-use-cases.md`**
  - Phase factory bullet → **kept in CLAUDE.md**
  - `match(true)` on `instanceof` bullet → **kept in CLAUDE.md**
  - Thin `execute()` pipeline bullet → **dropped** (redundant with Decomposition rule)
  - Shopwired/PricingUpdate pointer → **kept in CLAUDE.md**

## Files Touched

**New:**
- `.claude/rules/application-use-cases.md`
- `.claude/rules/application-client-interfaces.md`

**Modified:**
- `app/Application/CLAUDE.md` — remove 3 sections (Async Dispatch, Pre-Resolved Parameters subsection, Use Case Decomposition); trim Complex Use Case Reference (promote typed-result-objects bullet to scoped rule, drop thin-pipeline bullet as redundant); add a "Per-File Conventions" pointer list.

**New (traceability):**
- `.ai/plans/2026-04-24_649-application-rules-migration.md` — this file.
- `.ai/implementation-logs/649-application-rules-migration.md` — opened when work begins.

No code files change. No tests change. Single commit.

## Existing References To Reuse

- `.claude/rules/CLAUDE.md` — authoring principles (declarative, self-contained, named exceptions, trimming test).
- `.claude/rules/eloquent-repositories.md` / `eloquent-write-models.md` — format template for compact scoped rules.
- `.claude/rules/presentation-controllers.md` — format template for a multi-section file-shape rule with canonical pointers.
- `.ai/plans/2026-04-24_645-presentation-layer-scoped-rules-migration.md` — prior-migration plan format.
- Canonical classes to cite: `app/Application/Linnworks/UpdateCostPriceBySupplier/UpdateCostPriceBySupplierUseCase.php` (simple decomposition), `app/Application/Linnworks/Resolvers/SupplierGuidResolver.php` (resolver example), plus `Contracts/*ClientInterface.php` for the pre-resolved-parameters shape.

## Verification

1. **Trimming test per bullet** — for each bullet in the new rule files, remove it mentally and ask "would Claude still write compliant code from the surrounding code context and the linters alone?" Drop any bullet that survives this test.
2. **Tight-focus re-check** — every bullet in a scoped file must answer YES to "does this shape lines or structure local to the file matching the glob?" If layer-wide or only applies to a subset, move it back to CLAUDE.md.
3. **Content accounting pass** — before committing, cross-reference the bullet-level content-accounting list above against the diff; every removed section must land somewhere (scoped file OR retained in CLAUDE.md) and every kept section stays identical.
4. **Glob sanity check** — run `Glob` on `app/Application/**/*UseCase.php` and `app/Application/Contracts/**/*ClientInterface.php` to confirm the globs match existing files (not just future ones).
5. **`make lint` and `make test`** — per issue success criteria. Pure docs change, so both should be green; run as a sanity check.
6. **Fresh-session load check** — in a subsequent session, open a UseCase file and a ClientInterface file; confirm each scoped rule appears. Open a Domain file; confirm neither new rule loads.

## Rollout

Single commit on `feature/649-application-rules-migration` off `develop`. PR targets `develop`, squash-merged. The `paths:` loading mechanism was already validated by the Eloquent (#642) and Presentation (#644/#645) migrations — no pilot needed.
