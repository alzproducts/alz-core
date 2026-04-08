# Implementation Log: #496 RRP Retail Price System

**GitHub Issue**: #496
**Plan Document**: .ai/plans/2026-04-09_496-rrp-retail-price-system-shopwired-reconciliation.md
**Status**: Complete
**Started**: 2026-04-09
**Completed**: 2026-04-09

## Overview

Re-routes RRP (recommended retail price) from the broken ShopWired batch POST path to a per-SKU DB + PUT reconciliation system. ShopWired's `POST products/prices` doesn't support `comparePrice`, causing silent failures. The new architecture stores RRP per-SKU, derives product-level `comparePrice` via reconciliation, and sends it via `PUT products/{id}`.

## Decision Log

- Phase 0 (manual ShopWired PUT test) skipped — plan says `comparePrice ?? 0` for clearing
- Product.php class length (279→247): compacted self-documenting docblocks rather than extracting traits
- ProductExtraDataModel: excluded via `ExtraDataModel` suffix in PHPStan rule (mirrors `ViewModel` exclusion)
- `writeRrpPerSku()` per-item loop intentional — bounded (1-10 variations), preserves per-item error isolation
- EAGER_LOAD_RELATIONS for extraData: plan's explicit "zero new repository methods" approach; HasOne on UNIQUE index is minimal overhead
- Float comparison in reconciliation: epsilon-based (0.001) to avoid DB-vs-API precision drift
- `buildSkuRetailPrices` returns `null` (not `[]`) when relations not loaded — preserves sentinel contract
- Redundant `CREATE INDEX` removed from migration — UNIQUE already creates btree index

## Deviations from Plan

None significant. All 7 phases implemented as planned.

## Simplify Findings

Fixed:
- Redundant migration index (UNIQUE already creates btree)
- `buildSkuRetailPrices` sentinel contract (`[]` → `null` when unloaded)
- Float equality → epsilon comparison in reconciliation
- Removed redundant docblock from UpdateRetailPriceCommand

Skipped (intentional design):
- AbstractEloquentRepository compliance — single-method write repo, architectural decision
- Bulk upsert — per-item error isolation is intentional
- EAGER_LOAD_RELATIONS scope — plan's explicit zero-new-methods approach
- Double product read — reconciliation needs fresh data post-write

## Sweep Findings

Fixed:
- Added business logging to `UpdateProductRetailPricesUseCase` (extracted `buildResult()`)
- Added logging to orchestrator `UpdateProductPricesUseCase`
- Fixed stale docblock in ProductView (was referencing SQL view, now from extraData)
- Strengthened `extractRrp()` typing from `?Model` to `?ProductExtraDataModel`
- Removed unused `Sku` import from orchestrator

## Lint Fixes

- 3 new method length violations → refactored into smaller methods
- 4 baseline updates for existing code growth
- Product.php class 279→247 lines via docblock compaction
- PHPStan rule updated for `ExtraDataModel` suffix exclusion
- `writeRrpPerSku()` catch block expanded for DuplicateRecordException
- Removed stale OrderProductExtraDataModel phpstan.neon ignore entry

## Test Results

- 2943 tests pass (6738 assertions)
- 19 test failures from new `$rrp` constructor param — all fixed (added `rrp: null`)

## PR Notes

### What
Per-SKU RRP storage with ShopWired `comparePrice` reconciliation. Splits price updates between selling (batch POST) and retail (DB + PUT) paths via an orchestrator.

### Why
ShopWired's batch `POST products/prices` doesn't support `comparePrice`, causing all price updates containing RRP to silently fail (`updated: false`). RRP needs to be stored per-SKU (our granularity) and reconciled to product-level `comparePrice` (ShopWired's granularity).

### Key Decisions
- Per-SKU RRP in `catalog.product_extra_data` (not `shopwired.products`) — clean separation of our data vs synced data
- Reconciliation derives comparePrice: highest RRP when uniform selling price, null otherwise
- Orchestrator pattern: same `UpdateProductPricesUseCase` entry point, internally partitions commands
- Best-effort reconciliation — DB writes succeed even if PUT fails
- Epsilon float comparison for price equality (0.001 tolerance)

### Testing
- All 2943 existing tests pass
- New tests needed: reconciliation logic, orchestrator partitioning, retail price write use case
