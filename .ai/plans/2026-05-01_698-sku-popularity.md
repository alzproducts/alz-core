# Plan: Per-SKU Popularity on ProductVariationView

**Issue**: https://github.com/alzproducts/alz-core/issues/698
**Date**: 2026-05-01
**Branch**: _(not yet branched)_
**Status**: **READY — all decisions locked**
**Scope**: Mirror the existing product-level `Popularity` workflow at the SKU/variation level, exposing a `?Popularity` field on `ProductVariationView`. Adds a general-purpose SKU-canonicalisation primitive (`catalog.sku_aliases`) as a hard prerequisite.

---

## Background

The existing product-level Popularity pipeline ranks **products** by sales activity. Variation purchases roll up to their parent product because `shopwired.order_products.external_id` is the parent product ID — variant-level signal is collapsed.

We want the same ranking concept at **SKU/variation granularity**: each `ProductVariationView` exposes its own `?Popularity` reflecting only that variation's sales contribution.

Reusing the existing `App\Domain\Catalog\Product\ValueObjects\Popularity` VO unchanged is a goal — `Popularity::fromRank(?int $rank, ?int $max)` already handles the nullable case (no snapshot row for a variation), so consumers get clean opt-in semantics.

### Why this isn't a trivial copy-paste of the product pipeline

Order-line SKUs are **mutable strings** the merchant can rename via `operations.sku_changes`. A variation renamed `A → B → C` (each rename a completed `sku_changes` row) has its historical sales fragmented across three string keys. Naive `GROUP BY op.sku` would split that variation's percentile contribution across three rank buckets — silently wrong from day one.

The product pipeline doesn't suffer from this because it groups by `parent_external_id` (a stable integer ID, not an editable string).

**Solution**: a dedicated SKU-canonicalisation view (`catalog.sku_aliases`) maps every historical SKU to its current canonical "live" form via a recursive walk of `operations.sku_changes`. The popularity ranking view JOINs through this alias view before grouping.

`sku_aliases` is treated as a **general-purpose primitive in `catalog.*`**, not a popularity-specific helper. Its consumer set will expand over time (returns analysis, customer LTV by variation, supplier reconciliation, etc.) — design decisions on it should not be bounded to the popularity job's needs.

---

## Existing pipeline reference (read-only context)

The product pipeline lives in 4 migrations + 1 view extension:

| File | Object | Role |
|---|---|---|
| `2026_04_12_100000_create_catalog_product_popularity_ranking_config_table.php` | `catalog.product_popularity_ranking_config` table | Versioned algorithm parameters; partial unique on `is_active` |
| `2026_04_12_100001_create_catalog_product_popularity_ranking_view.php` | `catalog.product_popularity_ranking` VIEW | Expensive write-path: percentile-rank algorithm reading active config |
| `2026_04_12_100002_create_catalog_product_popularity_snapshots_table.php` | `catalog.product_popularity_snapshots` table | Append-only history; PK `(snapshot_date, parent_external_id)` |
| `2026_04_12_100003_create_catalog_product_popularity_ranking_latest_view.php` | `catalog.product_popularity_ranking_latest` VIEW | Cheap read-path: latest snapshot only |
| `2026_04_21_041810_add_popularity_to_catalog_products_view.php` | extend `catalog.products_view` | LEFT JOINs `popularity_rank` + `popularity_max` columns |

**Algorithm shape** (parameterised by config row, will be reused near-verbatim):
- Disjoint windows: `main` = (12mo ago → 2mo ago), `recent` = (2mo ago → now)
- Per-window: SUM `quantity` and `total` from non-cancelled, non-refunded orders
- Percentile-rank each metric (qty, turnover) within each window, **partitioned on `has_any_sales`** so non-sellers are pinned to rank 1 and don't dilute the seller pool
- Blend: `qty + turnover` weighted within window → `main + recent` weighted across windows → `final_score`
- `calculated_sort_order = (max_rank + 1) - ROUND(final_score)` so rank 1 = most popular, max = least popular seller / non-seller floor

**Domain VO** (`app/Domain/Catalog/Product/ValueObjects/Popularity.php`) — **REUSED UNCHANGED**:
```php
final readonly class Popularity {
    public function __construct(public int $rank, public int $max) { /* asserts */ }
    public static function fromRank(?int $rank, ?int $max): ?self { /* null-safe */ }
    public function level(int $segments = 5): int { /* bar-style level */ }
}
```

---

## Locked decisions

### D1. Pipeline shape: parallel
A wholly parallel set of objects mirrors the product pipeline structure exactly. Each pipeline owns its config, ranking view, snapshots, and latest view. Calibration evolves independently per pipeline.

### D2. Stage 0 prerequisite: `catalog.sku_aliases`
A new SQL VIEW shipped **before** the popularity pipeline lands. Spec:

- **Schema/name**: `catalog.sku_aliases`
- **Type**: plain VIEW (not materialised, not a table)
- **Columns**: `(live_sku VARCHAR, map_sku VARCHAR)` — minimum contract, no enrichment columns
- **Self-pairs included** (every `live_sku` appears as its own `map_sku`) so consumers get uniform JOIN semantics
- **Always-latest semantics**: no date guard — the recursive CTE always uses the current state of `operations.sku_changes`
- **Seed set**: `UNION` of `shopwired.product_variations.sku WHERE sku IS NOT NULL` and `shopwired.products.sku WHERE sku IS NOT NULL`
- **Recursive walk**: `JOIN operations.sku_changes sc ON sc.new_sku = parent.map_sku WHERE sc.completed_at IS NOT NULL`. Use `UNION` (not `UNION ALL`) for free cycle protection.

```sql
CREATE VIEW catalog.sku_aliases AS
WITH RECURSIVE chain(live_sku, map_sku) AS (
    SELECT v.sku, v.sku FROM shopwired.product_variations v WHERE v.sku IS NOT NULL
    UNION
    SELECT p.sku, p.sku FROM shopwired.products p WHERE p.sku IS NOT NULL
    UNION
    SELECT c.live_sku, sc.old_sku
    FROM chain c
    JOIN operations.sku_changes sc
        ON sc.new_sku = c.map_sku
        AND sc.completed_at IS NOT NULL
)
SELECT live_sku, map_sku FROM chain;
```

**Reasoning is captured in memory** (`feedback_shared_infra_scope.md`): this view is a public primitive, future consumers will lean on it, design decisions should not be justified by single-consumer assumptions.

### D3. Domain layer: reuse `Popularity` VO unchanged
`ProductVariationView` will gain a `?Popularity` field constructed via the existing `Popularity::fromRank()` static factory.

### D4. Snapshot table primary key
`PK (snapshot_date, live_sku VARCHAR)` — SKU is the canonical identity for this pipeline. VARCHAR PK overhead is negligible at expected pool size.

### D5. Snapshot reference columns
Mirrors product pipeline's audit-friendly layout with adapted identity columns:
- `algorithm_version SMALLINT` (FK to config table)
- `parent_external_id INT` — ShopWired parent product ID
- `variation_external_id INT NULL` — NULL for non-varying product SKUs, non-NULL for variation SKUs
- `title TEXT NULL` — point-in-time product/variation title
- `is_active BOOLEAN NULL` — point-in-time active state
- `current_sort_order INT NULL` — point-in-time ShopWired sort_order (from parent product)

No `live_source` column — `variation_external_id IS NULL` already distinguishes product vs variation SKUs.

### D6. Algorithm config: separate table
New `catalog.sku_popularity_ranking_config` with identical schema to `product_popularity_ranking_config`. Independent calibration per pipeline.

### D7. Algorithm seed values
Identical to product pipeline v1: `max_rank=12`, `main_period=12mo`, `recent_period=2mo`, `w_main=0.7`, `w_recent=0.3`, `w_qty=0.5`, `w_turnover=0.5`.

### D8. Read-path integration
Extend existing `catalog.product_variations_view` with `popularity_rank` + `popularity_max` columns via LEFT JOINs to `sku_popularity_ranking_latest` + `sku_popularity_ranking_config`. Same migration pattern as the product view extension.

### D9. PHP layer: VO in mapper
Build `Popularity::fromRank($model->popularity_rank, $model->popularity_max)` in `ProductVariationModelMapper::toViewDomain()`, pass `?Popularity` to `ProductVariationView` constructor. Mirrors how `ProductViewAssembler` builds the VO for `ProductView`.

### D10. API serialization
`'popularity' => $variation->popularity?->toArray()` in `ProductVariationResource::buildData()`. Returns `{rank, max, level}` when snapshot exists, `null` otherwise. Identical shape to `ProductResource`.

### D11. Snapshot job
New `SyncSkuPopularityRankingSnapshotJob` mirroring `SyncProductPopularityRankingSnapshotJob`. Identical config: `low` queue, Sunday 03:00 Europe/London, 3 tries, 3600s timeout, `ShouldBeUnique` with 6h window. Delegates to new `SnapshotSkuPopularityRankingUseCase`.

### D12. ShopWired write-back: none
ShopWired has no variation-level `sort_order`. The SKU pipeline is read-only. Stage 6 dropped.

### D13. Test coverage
Mirrors product pipeline's test strategy:
- **Stage 0** (`sku_aliases`): Feature test — single-hop, multi-hop, cycle, no-rename, deleted variation, `merge_products` reason
- **Stages 1–3** (config + ranking + snapshots): Feature test — fixture orders → expected ranks, verify INSERT...SELECT
- **Stage 4** (snapshot job): Feature test — use case execution, duplicate-fire throws `DuplicateRecordException`
- **Stage 5** (read-path): Integration test — `ProductVariationView` mapper round-trips popularity; API resource shape `{rank, max, level}`

---

## Stage breakdown (one PR per stage, sequenced)

> Each stage is independently mergeable to `develop`. Sequencing matters because Stages 1–5 depend on Stage 0 having landed.

### Stage 0 — `catalog.sku_aliases` view (Prerequisite, separate PR)

**Why separate PR**: the alias view is a general-purpose primitive whose review should stand alone. Bundling it with the popularity pipeline conflates the review of "is this canonicalisation correct?" with "is the ranking math right?".

- **New migration**: `2026_05_xx_create_catalog_sku_aliases_view.php`
- **Tests**: `tests/Feature/Catalog/SkuAliasesViewTest.php` covering single-hop, multi-hop, cycle, merge, no-rename, orphaned SKU
- **Touched files**: migration only; no PHP

### Stage 1 — Algorithm config table

- **New migration**: `2026_05_xx_create_catalog_sku_popularity_ranking_config_table.php`
- Schema mirrors `product_popularity_ranking_config` (same columns + constraints + partial unique on `is_active`)
- Seed v1: 12mo / 2mo, 0.7/0.3, 0.5/0.5, max_rank=12

### Stage 2 — Ranking view

- **New migration**: `2026_05_xx_create_catalog_sku_popularity_ranking_view.php`
- View body mirrors `product_popularity_ranking` with these changes:
  - `resolved_lines` JOINs `catalog.sku_aliases` to canonicalise `op.sku → live_sku`
  - `period_totals` GROUPs BY `live_sku` instead of `parent_external_id`
  - `products_with_totals` LEFT JOINs sales onto the seed catalog (variations + non-varying products) keyed by SKU
  - Output exposes `live_sku`, `parent_external_id`, `variation_external_id` for downstream snapshot rows

### Stage 3 — Snapshots table + latest view

- **Two migrations**:
  - `2026_05_xx_create_catalog_sku_popularity_snapshots_table.php` — PK `(snapshot_date, live_sku)`, reference columns: `algorithm_version` (FK), `parent_external_id`, `variation_external_id NULL`, `title NULL`, `is_active NULL`, `current_sort_order NULL`, plus all rank metric columns + trend
  - `2026_05_xx_create_catalog_sku_popularity_ranking_latest_view.php` — `SELECT * FROM snapshots WHERE snapshot_date = (SELECT MAX(snapshot_date) FROM snapshots)`

### Stage 4 — Snapshot job + scheduling

- **New use case**: `app/Application/Catalog/UseCases/SnapshotSkuPopularityRankingUseCase.php`
- **New repository**: snapshot INSERT...SELECT from ranking view
- **New job**: `app/Infrastructure/Jobs/Catalog/SyncSkuPopularityRankingSnapshotJob.php` — mirrors `SyncProductPopularityRankingSnapshotJob` (low queue, Sunday 03:00, 3 tries, 3600s timeout, ShouldBeUnique 6h)
- **Schedule**: registered in `CatalogScheduleServiceProvider` adjacent to product snapshot job

### Stage 5 — Read-path + Domain + Presentation wiring

- **Migration**: drop and recreate `catalog.product_variations_view` with LEFT JOINs to `sku_popularity_ranking_latest` (on `live_sku = v.sku`) and `sku_popularity_ranking_config` (on `algorithm_version`), exposing `popularity_rank` + `popularity_max`
- **Eloquent model**: `ProductVariationViewModel` — add `popularity_rank` and `popularity_max` property annotations + casts
- **Domain edit**: `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php` — add `?Popularity $popularity` property
- **Mapper edit**: `ProductVariationModelMapper::toViewDomain()` — build `Popularity::fromRank($model->popularity_rank, $model->popularity_max)` and pass to constructor
- **Resource edit**: `ProductVariationResource::buildData()` — add `'popularity' => $variation->popularity?->toArray()`

---

## Out of scope

- Recalibration of algorithm weights/windows for SKU-level signal (separate calibration exercise after baseline data exists)
- ShopWired variation sort_order write-back (ShopWired doesn't expose it)
- Backfill/historical snapshots — first-run snapshot is whatever the job produces on its first scheduled execution; no retroactive history needed
- Product-level Popularity changes — that pipeline stays as-is; the two coexist

---

## References

- Existing VO: `app/Domain/Catalog/Product/ValueObjects/Popularity.php`
- Target VO: `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php`
- Existing pipeline migrations: see "Existing pipeline reference" table above
- SKU rename audit: `database/migrations/2026_01_28_202332_create_operations_sku_changes_table.php`
- Order-line source: `database/migrations/2026_03_24_130000_create_shopwired_order_products_resolved_view.php` (already exposes `sku` with `sku_override`)
- Variation view: `database/migrations/2026_04_24_120000_fix_catalog_views_margin_zero_cost.php` (current `catalog.product_variations_view` definition)
- Memory: `feedback_shared_infra_scope.md` — `sku_aliases` is a public primitive, design decisions must not be bounded by popularity's needs
