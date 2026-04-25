# Domain Layer — Scoped Rules Migration

## Context

`.claude/rules/` path-scoped rules have now been proven by three prior migrations — Eloquent (#641→#642), Presentation (#643→#644/#645), and Infrastructure (#647). Each ported file-shape conventions out of always-loaded `CLAUDE.md` files into globbed rule files that auto-load only when Claude opens a matching file, eliminating token cost for irrelevant context.

Issue #648 is the Domain-layer counterpart. Source CLAUDE.md files in scope:

- `app/Domain/CLAUDE.md`
- `app/Domain/Catalog/CLAUDE.md`
- `app/Domain/Shared/Validation/CLAUDE.md`

Unlike the Infrastructure migration, there is **no significant drift** between the source CLAUDE.md content and canonical code — only three minor tightenings (documented in *Tightenings* below). The migration therefore runs as a **single commit in one PR**, not the two-stage drift-then-extract pattern used in #647.

## Design Principle

**Scoped rules point at canonical code; they do not duplicate it.** This is the authoring guidance in `.claude/rules/CLAUDE.md` — *"Don't list every method on a gateway Claude is calling — 'check the gateway first' is enough."*

Concretely for this migration:
- No exhaustive lists of exception subclasses or abstract bases.
- No replication of the `ValidatorInterface` / `DescribableValidationResultInterface` shape — the linter and `use` sites already enforce it.
- Canonical class pointers replace inline examples.

## Locked-in Decisions

Captured during `/grill-me`:

- **Three new rule files** — `domain-exceptions.md`, `domain-validators.md`, `infrastructure-view-assemblers.md`.
- **Native Domain Types table + Money Tax Type Selection stay in `app/Domain/CLAUDE.md`** — their scope is effectively every Domain file (`app/Domain/**/*.php`), and they shape Claude's mental model of the layer. Per the criterion in `.claude/rules/CLAUDE.md` ("CLAUDE.md prevents global misunderstandings"), they earn keep-status.
- **Wide exception glob** (`app/Domain/**/Exceptions/**/*.php`) — covers the 28 nested files under `Exceptions/Api/`, `Exceptions/Data/`, `Exceptions/Infrastructure/`, `Exceptions/Inventory/` that the issue's narrower proposed glob would have missed.
- **Tighten the "final" rule** — concrete exceptions are `final`; abstract bases (`AbstractApiException`, `PermanentApiFailure`, `TransientApiFailure`, `AbstractInfrastructureException`, `AbstractDataException`) hold shared shape and are not final. The original CLAUDE.md wording ("Must be `final` classes") overreaches; the tightened wording is accurate without needing a glob negation.
- **Validator glob covers both Validator and Result files** (`app/Domain/**/Validators/*.php`) — the trait/`orFail()` rules apply to `*Result.php`; the placement rule applies to both. One file, wide glob.
- **Assembler rule lives in the `infrastructure-*` family** — even though the source content is currently in `app/Domain/Catalog/CLAUDE.md`, the files it governs (`*ViewAssembler.php`) live in `app/Infrastructure/`. Naming as `infrastructure-view-assemblers.md` matches the family of `infrastructure-requests.md`, `infrastructure-response-dtos.md`, etc.
- **Delete `app/Domain/Catalog/CLAUDE.md`** — its only content (5 lines on assembler VO construction) ports to the new rule.
- **Delete `app/Domain/Shared/Validation/CLAUDE.md`** — its trait, naming, and placement rules port to `domain-validators.md`. The "Design Report" reference is stale.
- **No Golden Rule line added** to `app/Domain/CLAUDE.md` — the issue forbids net-new content, and the original document never had one. Reformulating "Domain Rarely Catches" into a labelled Golden Rule was tempting but deferred.
- **Single commit, single PR** — `chore(claude): scope Domain layer conventions to path-scoped .claude/rules files (#648)`.
- **Pointer style:** each new rule gets a one-line pointer in `app/Domain/CLAUDE.md` matching the shape used in `app/Infrastructure/CLAUDE.md` post-#647 (e.g. `> Eloquent repository patterns → .claude/rules/eloquent-repositories.md`).

## Tightenings (in-scope per issue)

The issue explicitly permits "Minor tightening (dropping noise, rewording for declarativeness) is expected and welcome." Three changes are made during the port:

| # | Source bullet | Tightening | Rationale |
|---|---|---|---|
| 1 | "Must be `final` classes with `readonly` constructor-promoted properties" | "DO declare concrete exceptions `final` with `readonly` constructor-promoted properties; abstract bases (e.g. `AbstractApiException`, `PermanentApiFailure`, `TransientApiFailure`) hold shared shape and are not final." | Five abstract base classes exist (`AbstractApiException`, `AbstractDataException`, `AbstractInfrastructureException`, `PermanentApiFailure`, `TransientApiFailure`). Original wording would mis-fire when editing one of them. |
| 2 | "Extend `\DomainException` or `\LogicException`" | Split by intent: "DO extend `App\Domain\Exceptions\DomainException` (or an abstract child) for business/runtime failures. DO extend `\LogicException` for programming errors (impossible states, coded mismatches)." | Zero production code extends PHP's built-in `\DomainException`. The project base is `App\Domain\Exceptions\DomainException` (extending `\RuntimeException`). The original wording is misleading on a class-name level and hides the runtime-vs-programming distinction. |
| 3 | "Use named constructors (`::fromFailedRecords()`) for complex creation logic" | Drop entirely. | Zero current Domain exception files use `from*` named constructors (verified: `mcp__intellij__search_in_files_by_regex` for `public static function from\w+\(` against `app/Domain/**/Exceptions/**/*.php` — no matches; example exists only in plan docs). Per `.claude/rules/CLAUDE.md` Trimming Test, a bullet that doesn't reflect real code is noise. |

Two further bullets are dropped as redundant rather than tightened:
- **`@throws` on interface methods** — actionable when editing an interface, not an exception. Already covered globally in `CLAUDE.md` Modern PHP Standards ("Implementations must copy `@throws` from interface and any called methods").
- **Naming: `*Interface`, `*Trait`** (from `Shared/Validation/CLAUDE.md`) — enforced by PHPArkitect Rule 6 + PHPStan Symplify rules. Per `.claude/rules/CLAUDE.md`: "Don't include anything the linter catches."

## Proposed Rule Files

Three new files, full content below as it should appear after the migration. Frontmatter glob, then bullet body, then canonical pointer. No hidden trims required during write — what's below is the final file.

### 1. `.claude/rules/domain-exceptions.md`

```yaml
---
paths:
  - "app/Domain/**/Exceptions/**/*.php"
---
```

```markdown
# Domain — Exception Class Rules

## Class Shape

- DO declare concrete exceptions `final` with `readonly` constructor-promoted properties carrying business context (IDs, amounts, status, service name, retry hint). Abstract bases (e.g. `AbstractApiException`, `PermanentApiFailure`, `TransientApiFailure`, `AbstractInfrastructureException`) hold shared shape and are not final.
- DO keep exception messages as static strings — no interpolated IDs, names, or other dynamic data. **Why:** static messages enable Sentry to group occurrences; interpolated values explode the group count.
- DO surface dynamic data via readonly properties returned from `context(): array` (override the base implementation).
- DO extend `App\Domain\Exceptions\DomainException` (or an abstract child like `PermanentApiFailure`, `TransientApiFailure`, `AbstractInfrastructureException`) for business/runtime failures.
- DO extend `\LogicException` for programming errors — impossible states, coded mismatches, fields that should never reach this branch. **Why:** `\LogicException` signals "this fired because the code is wrong, not because the runtime broke."

Canonical: `AuthenticationExpiredException` (DomainException chain via PermanentApiFailure → AbstractApiException → DomainException), `UnsupportedFieldException` (\LogicException).
```

**Match sample (34 files):** every file under `app/Domain/Exceptions/Api/`, `app/Domain/Exceptions/Data/`, `app/Domain/Exceptions/Infrastructure/`, `app/Domain/Exceptions/Inventory/`, plus top-level `app/Domain/Exceptions/{DomainException,InvalidConfigurationException,UnsupportedFieldException,ValidationFailedException}.php`, plus nested per-context exceptions in `app/Domain/Catalog/Product/Exceptions/`, `app/Domain/Catalog/CustomFields/Exceptions/`, `app/Domain/Linnworks/Exceptions/`, `app/Domain/CustomerService/Exceptions/`.

**Content ported from:** `app/Domain/CLAUDE.md` lines 14–21 (Exception Design Rules section), with the three tightenings applied and the `@throws` bullet dropped.

### 2. `.claude/rules/domain-validators.md`

```yaml
---
paths:
  - "app/Domain/**/Validators/*.php"
---
```

```markdown
# Domain — Validator + Validation-Result Rules

## Placement

- DO place validators in the concept's own `Validators/` subdirectory (e.g. `app/Domain/Catalog/Product/Validators/`, `app/Domain/Shared/Money/Validators/`). DO NOT add new validators under `app/Domain/Shared/Validation/` — that directory holds validation infrastructure (contracts, traits) only.

## Result Classes

- DO `use App\Domain\Shared\Validation\Concerns\ThrowsOnValidationFailureTrait` on single-result classes that need an `orFail()` entry point.
- DO `use App\Domain\Shared\Validation\Concerns\AggregatesChildResultsTrait` on aggregate-result classes that compose multiple child results — the aggregate trait already includes `ThrowsOnValidationFailureTrait`, so do not compose both.
- DO NOT implement `orFail()` manually — the trait is the single source of truth. If a result class needs `orFail()`, compose the trait; do not hand-roll.

Canonical:
- Single result: `MoneyEqualsValidator` + `MoneyEqualsResult`.
- Aggregate result: `VatRoundTripValidator` + `VatRoundTripAggregateResult`.
```

**Match sample (16 files):**
- `app/Domain/Shared/Money/Validators/{MoneyEquals,VatRoundTrip,NullableMoneyEquals}{Validator,Result,AggregateResult}.php`
- `app/Domain/Catalog/Product/Validators/{PriceChanged,SkuSupplierLink,SkuBelongsToProduct,HasValidRetailPricing,PriceCommandsVatRoundTrip}{Validator,Result}.php`

**Content ported from:** `app/Domain/Shared/Validation/CLAUDE.md` (whole file, minus the linter-enforced naming bullet and the stale Design Report reference) + `app/Domain/CLAUDE.md` lines 38–39 (Validators placement section).

### 3. `.claude/rules/infrastructure-view-assemblers.md`

```yaml
---
paths:
  - "app/Infrastructure/**/Mappers/*ViewAssembler.php"
---
```

```markdown
# Infrastructure — View Assembler Rules

## Responsibility

- DO orchestrate include checks, relation guards, factory wiring, and conditional embed selection in the assembler. The assembler decides *what* to assemble.
- DO delegate VO construction to a source-model factory (`Model::buildXxx(...)`), a dedicated mapper, or a self-constructing VO that accepts primitives and builds its own internal domain types. The assembler should not call `new SomeValueObject(...)` directly with derived fields.
- DO NOT construct VOs field-by-field inside the assembler — wiring nested `new` calls couples the assembler to every leaf VO's constructor signature and duplicates type-conversion logic that belongs on the VO.

Canonical: `ProductViewAssembler` — class docstring states the convention ("the VO self-constructs domain types from primitives"); `toViewDomain()` passes Eloquent column primitives directly into `new ProductView(...)`.
```

**Match sample (5 files):**
- `app/Infrastructure/Catalog/Order/Mappers/OrderViewAssembler.php`
- `app/Infrastructure/Catalog/Category/Mappers/CategoryViewAssembler.php`
- `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`
- `app/Infrastructure/Catalog/Brand/Mappers/BrandViewAssembler.php`
- `app/Infrastructure/Customer/Mappers/CustomerViewAssembler.php`

**Content ported from:** `app/Domain/Catalog/CLAUDE.md` (whole file). The source CLAUDE.md sits in Domain/Catalog but talks about files that live in Infrastructure — naming the rule `infrastructure-view-assemblers.md` follows the file location, not the source-doc location.

## CLAUDE.md Trimming

### `app/Domain/CLAUDE.md` — Final State

The trimmed file keeps architectural / mental-model content and adds two pointer lines. Section list (in order):

1. **Purpose** — keep verbatim (lines 3–4).
2. **What Belongs in Domain** — keep verbatim (lines 6–12).
3. **What NOT to Put in Domain** — keep verbatim (lines 22–26).
4. **Pointer**: `> Exception design rules → .claude/rules/domain-exceptions.md (auto-loads on app/Domain/**/Exceptions/**/*.php)`. Replaces the removed Exception Design Rules section.
5. **Domain Rarely Catches** — keep verbatim (lines 28–30).
6. **Assertions vs Exceptions** — keep verbatim (lines 32–35).
7. **Pointer**: `> Validator patterns → .claude/rules/domain-validators.md (auto-loads on app/Domain/**/Validators/*.php)`. Replaces the removed Validators section.
8. **Native Domain Types** — keep verbatim including the full table (lines 45–60). The standalone "Integer IDs" section (lines 41–43) is removed; its content is already covered by the `IntId` row in the table.
9. **Money Tax Type Selection** — keep verbatim including the table (lines 62–73).

**Removed sections:**
- Exception Design Rules (lines 14–21) — now in `domain-exceptions.md`.
- Validators (lines 38–39) — now in `domain-validators.md`.
- Integer IDs (lines 41–43) — duplicates the `IntId` row of the Native Domain Types table; net-redundant.

### `app/Domain/Catalog/CLAUDE.md` — Deleted

The whole file (5 lines) is the assembler-VO-construction rule. After porting to `infrastructure-view-assemblers.md`, the file has no remaining content. Delete it (`git rm`).

### `app/Domain/Shared/Validation/CLAUDE.md` — Deleted

Content distributes:
- "Validators live with their domain concept in Validators/ subdirectories — never here" → moved into `domain-validators.md` (Placement section).
- "Single results: use ThrowsOnValidationFailureTrait" → moved into `domain-validators.md` (Result Classes section).
- "Aggregate results: use AggregatesChildResultsTrait" → moved into `domain-validators.md` (Result Classes section).
- "Never implement orFail() manually" → moved into `domain-validators.md` (Result Classes section).
- "Naming: *Interface (PHPArkitect Rule 6), *Trait (PHPStan symplify)" → dropped (linter-enforced).
- "Design Report → .ai/reports/domain-validator-report.md" → dropped (stale reference; the report records design history, not active conventions).

After redistribution, the file has no remaining content. Delete it (`git rm`).

## Files Touched

**Created:**
- `.claude/rules/domain-exceptions.md`
- `.claude/rules/domain-validators.md`
- `.claude/rules/infrastructure-view-assemblers.md`

**Modified:**
- `app/Domain/CLAUDE.md` — remove Exception Design Rules, Validators, and Integer IDs sections; add two pointer lines.

**Deleted:**
- `app/Domain/Catalog/CLAUDE.md`
- `app/Domain/Shared/Validation/CLAUDE.md`

**Untouched:**
- All Domain code (no behaviour changes, this is documentation movement only).
- All other nested CLAUDE.md files outside Domain (already in their respective scoped-rule families).
- `app/Infrastructure/CLAUDE.md` (already migrated by #647).

Implementation log at `.ai/implementation-logs/issue-648-domain-rules-migration.md`, following the template in `.ai/implementation-logs/CLAUDE.md`.

## Rollout

Single PR, single commit:

**Commit — `chore(claude): scope Domain layer conventions to path-scoped .claude/rules files (#648)`**
- Creates the three scoped rule files.
- Trims `app/Domain/CLAUDE.md` (remove three sections, add two pointers).
- Deletes `app/Domain/Catalog/CLAUDE.md` and `app/Domain/Shared/Validation/CLAUDE.md`.
- `make lint` and `make test` pass.

No two-stage drift correction (unlike #647) — the only tightenings are the three minor wording changes catalogued above, all of which can be applied directly to the new rule file content during the port.

## Implementation Steps (for executor)

Execute in order; each step is independently verifiable.

1. **Read** `app/Domain/CLAUDE.md`, `app/Domain/Catalog/CLAUDE.md`, `app/Domain/Shared/Validation/CLAUDE.md` end-to-end. Confirm the section line numbers in this plan still match (in case of upstream edits since plan was written).
2. **Create** `.claude/rules/domain-exceptions.md` with frontmatter + body exactly as specified in §1 above.
3. **Create** `.claude/rules/domain-validators.md` with frontmatter + body exactly as specified in §2 above.
4. **Create** `.claude/rules/infrastructure-view-assemblers.md` with frontmatter + body exactly as specified in §3 above.
5. **Edit** `app/Domain/CLAUDE.md`:
   - Remove the "Exception Design Rules" section (heading + bullets, currently lines 14–21).
   - Insert pointer line after "What NOT to Put in Domain" section: `> Exception design rules → .claude/rules/domain-exceptions.md (auto-loads on app/Domain/**/Exceptions/**/*.php)`
   - Remove the "Validators" section (heading + body, currently lines 37–39).
   - Insert pointer line in its place: `> Validator patterns → .claude/rules/domain-validators.md (auto-loads on app/Domain/**/Validators/*.php)`
   - Remove the "Integer IDs" section (heading + body, currently lines 41–43).
   - Verify "Native Domain Types" and "Money Tax Type Selection" sections are unchanged.
6. **Delete** `app/Domain/Catalog/CLAUDE.md` via `git rm`.
7. **Delete** `app/Domain/Shared/Validation/CLAUDE.md` via `git rm`.
8. **Run** `make lint`. Expect green — no code changes were made.
9. **Run** `make test`. Expect green — no code changes were made.
10. **Spot-check rule loading** (Verification §1 below) by opening a representative file from each glob in a fresh Claude Code session.
11. **Apply Trimming Test** (Verification §5 below) to each bullet in the three new files. Drop bullets that fail.
12. **Commit** with message `chore(claude): scope Domain layer conventions to path-scoped .claude/rules files (#648)`.

## Verification

1. **Open one file matching each new rule's glob** in a fresh session and confirm the rule loads:
   - `app/Domain/Exceptions/Api/AuthenticationExpiredException.php` → `domain-exceptions.md` loads.
   - `app/Domain/Exceptions/UnsupportedFieldException.php` → `domain-exceptions.md` loads (top-level Exceptions/ file, not nested — confirms the `**/*.php` part of the glob).
   - `app/Domain/Catalog/Product/Exceptions/MissingVariationSkuException.php` → `domain-exceptions.md` loads (per-context exception under a non-Exceptions parent — confirms the `app/Domain/**/Exceptions/` part of the glob).
   - `app/Domain/Shared/Money/Validators/MoneyEqualsValidator.php` → `domain-validators.md` loads.
   - `app/Domain/Shared/Money/Validators/MoneyEqualsResult.php` → `domain-validators.md` loads (Result file under Validators/).
   - `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` → `infrastructure-view-assemblers.md` loads.
2. **Open a non-matching Domain file** (e.g. `app/Domain/ValueObjects/IntId.php`) and confirm none of the three new rules load.
3. **Open a non-matching Infrastructure mapper** (e.g. `app/Infrastructure/Linnworks/Mappers/StockItemModelMapper.php` — a non-assembler mapper) and confirm `infrastructure-view-assemblers.md` does NOT load.
4. **Confirm `app/Domain/CLAUDE.md` still contains** the Native Domain Types table and Money Tax Type Selection table — these were the only file-shape-ish bullets explicitly kept in the parent doc.
5. **Apply the Trimming Test** from `.claude/rules/CLAUDE.md` to each bullet in each new file: remove the bullet, ask whether Claude would still write compliant code given the canonical-class pointer + surrounding code. If yes, drop the bullet before committing.
6. `make lint` passes (Pint + PHPStan + PHPArkitect + Deptrac + TLint).
7. `make test` passes (no test changes expected — pure documentation move).

## Out of Scope

- **New conventions not already in the source CLAUDE.md files** — e.g. value-object equality patterns, command-vs-query naming, exception-context standardisation. The issue forbids net-new content.
- **Adding a Golden Rule line to `app/Domain/CLAUDE.md`** — the original document has none, so adding one is net-new content. Considered during the grill; deferred.
- **Reformulating "Domain Rarely Catches" into a labelled Golden Rule** — borderline scope creep. Section stays as-is.
- **Restructuring the Native Domain Types table** — table stays in `Domain/CLAUDE.md` per the locked decision. Splitting into per-context VO rules (e.g. `domain-money.md`, `domain-identifiers.md`) is a future refactor at best.
- **Validator-result naming conventions** — `*Result` vs `*ValidationResult` vs `*Outcome`. Not currently in any CLAUDE.md, so out of scope.
- **Linter-enforced bullets** — the `*Interface` / `*Trait` naming rule from `Shared/Validation/CLAUDE.md` is dropped on porting because PHPArkitect Rule 6 + PHPStan Symplify already enforce it. Not a "removal" decision in the sense of dropping behaviour — just removing redundant prose.
- **Nested integration CLAUDE.md files under Infrastructure** — already excluded by #647 and not touched here.

## Grill Notes (for context)

The grill session (`/grill-me GitHub issue 648`) walked the design tree in order:

1. **Q1 — File split.** Three options: 4 files (Native Types migrates), 3 files (Native Types stays), 5 files (Money Tax separate). User chose **3 files** — Native Types and Money Tax stay in `Domain/CLAUDE.md` because their scope is "every Domain file" and they shape Claude's mental model of the layer. Lock established the file count for everything downstream.
2. **Q2 — Exception glob.** Three options: wide glob + tightened "final" rule, wide glob + Abstract* negation, narrow glob from the issue. User chose **wide glob + tightening**. The literal issue glob (`app/Domain/**/Exceptions/*.php`) would have missed 28 of 34 exception files (everything in `Exceptions/Api/`, `Exceptions/Data/`, `Exceptions/Infrastructure/`, `Exceptions/Inventory/`).
3. **Q3 — Validator glob v1.** User initially chose narrow `*Validator.php` glob. **Corrected mid-grill** when verification of `MoneyEqualsValidator` and `MoneyEqualsResult` showed the trait is on the *Result* class, not the Validator class. Re-asked.
4. **Q3-revised — Validator glob v2.** Three options: one file with wide `*.php` glob, two files with narrow globs, one file targeting `*Result.php` only. User chose **one file, wide glob `app/Domain/**/Validators/*.php`** — bullets cover placement (both file types) and trait usage (Result files), with the cost of the trait bullets loading harmlessly on Validator files.
5. **Q4 — Shared/Validation/CLAUDE.md fate.** Three options: delete, trim to placement-only stub, keep with pointer. User chose **delete**. The placement rule moves into `domain-validators.md` Placement section; remaining content is either ported or stale.
6. **Q5 — Assembler rule glob + filename.** Three options: `infrastructure-view-assemblers.md` with `*ViewAssembler.php` glob, `infrastructure-mappers.md` with all mappers, `catalog-view-assemblers.md` Catalog-only. User chose **`infrastructure-view-assemblers.md` + `*ViewAssembler.php`**. Catalog-only would have missed `CustomerViewAssembler`; wide-mappers would have fired the rule on non-assembler mappers.
7. **Q6 — Catalog/CLAUDE.md fate.** Two options: delete, trim to stub with pointer. User chose **delete** — the file's whole content is the one ported rule.
8. **Q7 — Inheritance rule phrasing.** User chose **split by intent** (`App\Domain\Exceptions\DomainException` for runtime, `\LogicException` for programming). Verification showed zero production exceptions extend PHP's built-in `\DomainException`; the original CLAUDE.md wording is misleading.
9. **Q8 — Named constructors bullet.** User chose **drop** — zero current exceptions use `from*` named constructors (verified by regex search).
10. **Q9 — `@throws` on interfaces bullet.** User chose **drop** — bullet not actionable when editing an exception file; already covered globally in `CLAUDE.md` Modern PHP Standards.
11. **Q10 — Integer IDs section.** User chose **remove** — duplicates the `IntId` row of the Native Domain Types table.
12. **Q11 — Golden Rule.** User chose **no golden rule** — adding one is out of scope per the issue's "no new content" directive.
13. **Q12 — Commit shape.** Two options: single commit, two-commit (drift then extract). User chose **single commit** — drift here is small (3 minor tightenings), unlike #647 where two-stage was justified by 13 drift items.
14. **Q13 — Canonical class pointers.** User accepted the recommended pointers: `AuthenticationExpiredException` + `UnsupportedFieldException` (exceptions); `MoneyEqualsValidator/Result` + `VatRoundTripValidator/AggregateResult` (validators); `ProductViewAssembler` (assemblers). `ProductViewAssembler` validated by reading its docstring ("the VO self-constructs domain types from primitives") which exactly states the rule.

Two judgement calls flagged for the PR description:

- **Native Domain Types stays in `Domain/CLAUDE.md`** — different choice from #647, where comparable file-shape content was extracted. Justified because the table's scope is genuinely "every Domain file" and the table shapes Claude's mental model of the layer.
- **Assembler rule lives in the `infrastructure-*` family** despite source content sitting in a Domain CLAUDE.md — naming follows file location, not source-doc location.
