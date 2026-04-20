# Implementation Log: Expose Popularity on ProductView

**GitHub Issue**: #601
**Plan Document**: .ai/plans/2026-04-21_601-expose-popularity-on-product-view.md
**Status**: In Progress
**Started**: 2026-04-21
**Completed**: —

## Overview

Expose raw popularity rank (from `catalog.product_popularity_ranking_latest`) alongside the existing ShopWired `sort_order`, as a nested `popularity: { rank, max } | null` object in the Product API response. Introduces a `Popularity` domain VO with a `bucket()` method to encapsulate the "lower rank = more popular" inversion.

## Decision Log

### 2026-04-21
- **Decision**: Follow plan verbatim — nested `popularity: { rank, max } | null` alongside flat `sort_order`.
- **Why**: `sort_order` is a ShopWired write channel that will diverge from raw popularity once boost logic lands. Read-side should source from snapshot pipeline directly.
- **Tradeoff**: One extra API field; slight wire shape duplication today since `sort_order` ≈ `popularity.rank`.

### 2026-04-21
- **Decision**: Join `catalog.product_popularity_ranking_config` by `algorithm_version`, not `is_active = true`.
- **Why**: Preserves the `rank ≤ max` invariant across config rotations. Stale snapshots keep the max they were produced under.

## Deviations from Plan

- Plan §7 test list mentioned a standalone `ProductResource` assertion test; rolled that into `ProductControllerTest` as `response_exposes_nested_popularity_object_when_present` (uses existing controller harness, covers the same contract — no separate resource test needed).

## Implementation Summary (Step 3)

**New files**
- `app/Domain/Catalog/Product/ValueObjects/Popularity.php` — readonly VO with `fromRank()` nullable factory and `bucket($segments = 5)` method.
- `database/migrations/2026_04_21_041810_add_popularity_to_catalog_products_view.php` — `CREATE OR REPLACE VIEW` (up) appends `popularity_rank`/`popularity_max`; `DROP ... CASCADE` + `CREATE VIEW` (down) restores prior state verbatim.
- `tests/Unit/Domain/Catalog/Product/ValueObjects/PopularityTest.php` — 16 tests: invariants, nullable factory, `bucket()` edges.

**Modified**
- `app/Domain/Catalog/Product/ValueObjects/ProductView.php` — appended `public ?Popularity $popularity = null` after `mainCategoryIds`.
- `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` — imports `Popularity`, passes `Popularity::fromRank($model->popularity_rank, $model->popularity_max)`.
- `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php` — docblock `@property int|null $popularity_rank|$popularity_max` and numeric casts.
- `app/Presentation/Http/Api/Resources/ProductResource.php` — `popularity` key emits `['rank' => …, 'max' => …]` or `null`.
- `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php` — `popularity` added to `$expectedKeys`, helper signature extended, new `response_exposes_nested_popularity_object_when_present` test.

## Step 5 — Lint

Three PHPStan errors surfaced after Step 4:

1. `Popularity::bucket()` missing `@throws DivisionByZeroError` — added import + docblock annotation. The error is unreachable (constructor enforces `max ≥ 2`), but PHPStan cannot trace value-level invariants through primitive `int` properties.
2. `ProductView::__construct` baseline entry shifted from 66 → 67 lines after appending the `?Popularity $popularity = null` parameter. Updated existing baseline entry per CLAUDE.md rule ("update existing entries when line counts shift, never add new ones").
3. `ignore.unmatched` for the stale 66-line baseline — resolved by (2).

Post-fix: `make lint` → `EXIT: 0`. Pint, PHPStan, PHPArkitect, Deptrac, TLint all green.

## Step 7 — Simplify Review

Ran 3 parallel review agents (reuse, quality, efficiency). Applied findings after fact-checking:

**Accepted:**
- Added `Popularity::toArray()` (matches 7 sibling VOs: ProductStock, ProductSupplier, ProductImage, etc.). Collapsed the 4-line conditional in `ProductResource::baseFields()` to `$product->popularity?->toArray()`, which dropped that method from 42 → 39 lines.
- Reworded the `@throws DivisionByZeroError` docblock (removed "never in practice" phrasing that misrepresented the annotation's meaning).
- Deleted narration comment in migration `down()` referring to the previous migration filename.
- Deleted redundant `assertArrayHasKey('sort_order', ...)` from new popularity test (`sort_order` already in `$expectedKeys`).

**Rejected after fact-check:**
- "Missing index on `parent_external_id`" — verified `idx_popularity_snapshots_product_history` covers `(parent_external_id, snapshot_date)` on the underlying snapshot table.
- "Constructor should null-on-bad-data" — violates CLAUDE.md domain assertion policy: internal contract violations must fail loudly. The snapshot pipeline owns data integrity.
- "Unreachable buckets comment is narration" — judgment call kept; documents a non-obvious invariant callers must understand (gap buckets at small `max`).

Baseline update: `baseFields()` 42 → 39 lines (existing entry, allowed per CLAUDE.md).

Final: `make lint` EXIT 0, `make test-quick` EXIT 0.

## Step 8 — Sweep

Delegated to general-purpose subagent using `.claude/commands/sweep.md`. **No fixes applied** — branch was already clean after the simplify pass. Checklist coverage confirmed:
- Presentation: thin delegation, no business logic leak
- Testing: strong `assertSame` assertions, proper organization
- Infrastructure: named factory construction, proper casts + docblock
- Domain: self-sufficient VO, webmozart/assert for internal contracts
- Cross-cutting: baseline changes are line-count-only on 3 existing entries, zero linting bypasses

Final: `make lint` EXIT 0, `make test-quick` EXIT 0 (1539 passed, 2858 assertions).

## Blockers / Open Questions

_None._

## Step 4 — Test Results

- `pest --filter='Popularity|ProductController|ProductView'`: **121 passed** (221 assertions). Includes the new `PopularityTest` (16 tests) and new `response_exposes_nested_popularity_object_when_present` controller test.
- `make test-quick` (Domain suite): **1539 passed** (2858 assertions) in 8.3s. No regressions.

## Technical Notes

- `Popularity::bucket()` accepts configurable `$segments` but is not exposed on the wire — domain-facing for PHP consumers (email, PDF). Frontend computes fill level itself from `rank`+`max`.
- Migration uses `CREATE OR REPLACE VIEW` for up, but `DROP VIEW ... CASCADE` + `CREATE VIEW` for down (Postgres cannot drop/reorder columns via `CREATE OR REPLACE`).
- Filename contains `catalog` so schema resets pick it up (per `database/CLAUDE.md`).

## PR Notes

### What
Adds `popularity: { rank, max } | null` to `GET /api/products` responses, sourced from the popularity snapshot pipeline rather than `sort_order`.

### Why
The admin product grid needs a popularity column rendered as signal-style bars. Reading from `sort_order` would silently drift once sale/featured boost logic lands.

### Key Decisions
- Nested `popularity` object alongside existing `sort_order` (no removal of existing field).
- New `Popularity` VO in Domain, with `bucket()` for visual segmentation.
- Config join by `algorithm_version` to preserve `rank ≤ max` invariant.

### Testing
- New `PopularityTest` covers invariants and `bucket()` edges.
- `ProductControllerTest` updated to include `popularity` in `$expectedKeys`.
- Manual verification via tinker query against `catalog.products_view`.
