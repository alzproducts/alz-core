# Implementation Log: #489 — Enrich Product Supplier Data via Eager Loading

## Branch
`feature/489-enrich-product-supplier-eager-loading`

## Plan
`.ai/plans/2026-04-07_489-enrich-product-supplier-data-via-eager-loading.md`

## Status: Complete (2026-04-07)

## Decision Log
- Following plan as specified — 5 change areas
- Used `Money::nonZeroOrNull()` for all monetary fields (treats 0.0 as null — intentional for supplier pricing context)
- Ordering preserved via `sortByDesc('is_default')` in assembler (not on relation definition, to avoid PHPStan dynamic call issue)

## Implementation Progress
- [x] Enrich `ProductSupplier` VO with 8 new fields, upgrade `purchasePrice` to `?Money`
- [x] Add `toProductSupplier()` to `StockItemSupplierModel`
- [x] Update `relationsForIncludes()` for eager loading
- [x] Replace factory with eager-loaded relation in `ProductViewAssembler`
- [x] Update `ProductSupplierFactory` to use `Money::nonZeroOrNull()` for `purchasePrice`
- [x] Update tests constructing `ProductSupplier` with `Money::exclusive()` (2 test files)

## Tests & Lint
- All 2912 tests pass (6651 assertions)
- All 5 linters pass (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
- Fixed `Money::amount()` → `Money::toNet()` (private property, must use accessor)
- Extracted `$has` closure in `relationsForIncludes()` to meet 20-line method limit
- Updated complexity baseline for `EloquentProductRepository` (849→853 lines)
- Removed duplicate `Money` import in `UpdateCostPriceUseCaseTest`

## Simplify Fixes
- Removed redundant `stockItem` eager load (implied by `stockItem.suppliers`)
- Added `sortByDesc('is_default')` in assembler to preserve old factory's `ORDER BY is_default DESC`
- Removed defensive `relationLoaded('suppliers')` guard (consistent with sibling resolvers)

## Sweep Fixes
- Removed double blank line in `EloquentProductRepository`
- Restored precise `@return` array shape on `ProductSupplier::toArray()` (11 typed fields)
- Updated complexity baseline (854→853)

## PR Notes

### What
Replaces the global `ProductSupplierFactory` (which loads ALL suppliers into memory on each request) with scoped Eloquent eager loading via `stockItem.suppliers`, and enriches `ProductSupplier` with 8 new fields.

### Why
Performance: the factory loaded every supplier for every SKU in the database, then only used a handful per page. Eager loading fetches only the suppliers for the current result set. Additionally, `ProductSupplier` was missing procurement and pricing data (MPN, lead time, min/max/average prices).

### Key Decisions
- `purchasePrice` upgraded from `?float` to `?Money` — all monetary fields use `Money::exclusive()`
- Factory retained for `UpdateCostPriceBySupplierUseCase` (separate PR cleanup)
- Ordering preserved via `sortByDesc('is_default')` in assembler
- New fields all nullable with defaults (backwards-compatible constructor)
