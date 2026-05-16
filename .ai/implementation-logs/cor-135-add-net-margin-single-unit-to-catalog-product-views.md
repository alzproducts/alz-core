# Implementation Log: Add net_margin_single_unit to Catalog Product Views

**Linear Issue**: COR-135
**Plan Document**: .ai/plans/2026-05-16_COR-135-add-net-margin-single-unit-to-catalog-product-views.md
**Branch**: feature/cor-135-add-net_margin_single_unit-column-to-catalog-product-views
**Status**: In Progress
**Started**: 2026-05-16
**Completed**: —

## Overview

Adds `net_margin_single_unit` to `catalog.products_view` and `catalog.product_variations_view`. Shows the floor margin when a single unit ships and the business absorbs the full delivery cost on free-delivery products. For non-free-delivery products the value collapses to `profit_margin` via LEFT JOIN + COALESCE.

## Decision Log

### 2026-05-16

- **Decision**: Implement per plan; treat plan as authoritative source.
  - **Why**: Plan was finalised before this session, has explicit SQL fragments, docblock additions, and a documented accepted risk.
- **Decision**: Use LEFT JOIN on `catalog.free_delivery_shipping_costs` with `COALESCE(fdsc.cost, 0)` rather than CASE inside the formula.
  - **Why**: Same SQL collapses to `profit_margin` for non-free-delivery products with no special-casing.
- **Decision**: Two migrations — table first, then view rebuild.
  - **Why**: Matches existing repo convention (each view rebuild is its own migration; the lookup table is independent and re-usable).

## Deviations from Plan

- Table migration uses `Schema::create()` + `DB::table()->insert()` instead of the plan's raw `DB::statement('CREATE TABLE …')` / `DB::statement('INSERT …')`. Reason: matches the established repo convention for catalog lookup tables (e.g. `2026_04_12_100000_create_catalog_product_popularity_ranking_config_table.php`). Functionally identical — same NUMERIC(8,2) cost column, same primary key on `delivery_type`, same two seed rows.
- In `catalog.product_variations_view`, `net_margin_single_unit` is placed after `stock_value` (per plan), which is mid-list — variation_title and parent_* columns follow. That matches the plan literally; column-order within a view is non-semantic.

## Progress

- 2026-05-16 — Both migrations + both ViewModels written. `php artisan migrate` applied cleanly. `migrate:rollback --step=2` cleanly restored prior view definitions; re-migrating returned to the new state. SQL is valid Postgres.
- 2026-05-16 — `make test` (pest --parallel) → 3388 passed, 0 failed (1 risky + 12 notices are pre-existing GracefulCache Mockery LoggerInterface warnings, unrelated to this change).
- 2026-05-16 — `make lint` clean: Pint, PHPStan (max + ShipMonk + bleeding edge, 1328 files, 0 errors), PHPArkitect, Deptrac, TLint.
- 2026-05-16 — Simplify (3 parallel reviewer agents — reuse/quality/efficiency):
  - **Applied:** Dropped redundant `0::numeric` cast (`COALESCE(fdsc.cost, 0)` — Postgres promotes the literal); trimmed restating comment block on the `net_margin_single_unit` CASE in both views (kept the NULL-when-cost-price-missing parity note).
  - **Considered, declined:** CHECK constraint on `delivery_type` — wouldn't actually close the documented drift gap (which is a *JOIN-miss on bad ShopWired data*, not bad lookup-table data) and would couple the lookup table to a hardcoded enum-value list.
  - Migration cycle re-verified post-fix.
- 2026-05-16 — Sweep (general-purpose subagent): clean — no fixes to apply.
- 2026-05-16 — Validation against local Supabase Postgres (read-only SELECTs from the views):
  - `products_view` with `has_free_delivery=true, cost_price IS NOT NULL`: 5/5 rows show `net_margin_single_unit < profit_margin` as expected (e.g. SKU `AM-CS001`: 35.02 → 16.75).
  - `products_view` with `has_free_delivery=false, cost_price IS NOT NULL`: 5/5 rows show `net_margin_single_unit = profit_margin` exactly — COALESCE-to-0 collapse confirmed.
  - NULL invariant: 0 violations on both `products_view` and `product_variations_view` (`cost_price IS NULL ∧ net_margin_single_unit IS NOT NULL` returns 0).
  - `product_variations_view` parent-driven free delivery: 3/3 rows show `net_margin_single_unit < profit_margin` as expected.
  - Eloquent hydration on `ProductViewModel::first()->net_margin_single_unit`: returns `float` (`gettype === "double"`, value `25.0`) — cast wired up correctly.

## Blockers / Open Questions

(none yet)

## Technical Notes

- Down migrations must restore the exact prior view body. Plan points at the two source migrations to copy verbatim:
  - `products_view down` ← `2026_04_24_120000_fix_catalog_views_margin_zero_cost::up()`
  - `variations_view down` ← `2026_05_06_100001_add_supplier_name_and_stock_value_to_catalog_product_variations_view::up()`
- Accepted risk: malformed `free_delivery` custom field values silently produce `net_margin_single_unit = profit_margin` (no JOIN match). Documented in plan; not adding validation.

## PR Notes

### What
Adds a new `net_margin_single_unit` column to both `catalog.products_view` and `catalog.product_variations_view`, backed by a new `catalog.free_delivery_shipping_costs` lookup table (Standard=£3.50, Express=£4.50, VAT-exclusive). The column shows the worst-case margin when a single unit ships and absorbs the full delivery cost.

### Why
`profit_margin` ignores absorbed shipping costs on free-delivery products and so overstates real margin on single-unit orders. The new column makes the floor margin explicit and visible alongside the gross margin.

### Key Decisions
- LEFT JOIN + `COALESCE(fdsc.cost, 0)`: non-free-delivery products have no JOIN match, fall back to 0, and the formula collapses to the existing `profit_margin`.
- Two migrations — lookup table separate from view rebuild, mirroring repo convention.
- Exact-string equality on `delivery_type` is accepted as a silent fallthrough risk for malformed custom fields.

### Testing
- `make lint`
- `make test`
- Read-only SQL spot checks (see plan §Verification): rows with `has_free_delivery=true` should show `net_margin_single_unit < profit_margin`; rows with `has_free_delivery=false` should show equality.
