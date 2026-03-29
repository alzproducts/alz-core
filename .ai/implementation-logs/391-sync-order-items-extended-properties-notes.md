# Implementation Log: #391 — Sync Linnworks Order Items, Extended Properties & Notes

## Status: Complete (pending sweep)

## Decision Log

- **2026-03-29**: Started implementation following plan
- Child sync uses upsert+delete-orphans (not delete+re-insert) because items/EPs have stable RowIds
- Notes stored as JSONB column (small collection, no independent queryability)
- `saveOrdersBulk()` removed; UseCase switches to `saveMany()` which calls `save()` per order
- Used `foreach` loops instead of `array_map` in OrderResponse/OrderItemResponse to avoid PHPStan callable type issues with Spatie Data arrays
- Added `@var` type annotations in mapping methods for PHPStan type narrowing
- `@throws` propagated through repository -> use case -> job chain

### Simplify Fixes Applied
- **createDate/lastUpdate nullable** — `OrderExtendedPropertyResponse` was fabricating timestamps with `'now'` fallback. Fixed: domain fields now nullable, DTO uses `LinnworksDateParser::parse()`
- **is_cancelled cast** — Pre-existing missing boolean cast in `LinnworksOrderModel`. Added.
- **Single-loop sync** — Merged double `array_map` in `syncItems`/`syncExtendedProperties` into single foreach
- **Stale docstrings** — Updated "bulk upsert" references in UseCase to "per-order transactional save"
- **DomainConvertibleInterface** — Added to `OrderNoteResponse` and `OrderExtendedPropertyResponse` for consistency

## Phases — All Complete
- [x] Phase 1: Domain VOs (LinnworksOrderItem, LinnworksOrderExtendedProperty, LinnworksOrderNote)
- [x] Phase 2: Response DTOs (6 new DTOs + OrderResponse updated)
- [x] Phase 3: Migrations (2 new tables + notes JSONB column)
- [x] Phase 4: Models (2 new models + LinnworksOrderModel updated)
- [x] Phase 5: Repository (transactional save with upsert+orphan cleanup)
- [x] Phase 6: UseCase + Interface (saveOrdersBulk -> saveMany)
- [x] Tests: 2760 passing (6226 assertions)
- [x] Lint: All 5 linters pass clean

## PR Notes

### What
Persist order items, extended properties, and notes from the Linnworks v2 GetOrders API response, which was previously discarded.

### Why
Incomplete order data — could see totals/addresses but not what was actually ordered. Needed for downstream analytics and fulfilment workflows.

### Key Decisions
- **Upsert+delete-orphans** (not delete+re-insert) for items/EPs — stable RowIds from Linnworks enable efficient diff-based sync
- **JSONB for notes** — small collection, no independent queryability needs
- **Per-order transactions** — shifted from `saveOrdersBulk()` to `saveMany()` → `save()` for atomic parent+child persistence. Known trade-off: more DB round-trips but required for relational integrity
- **CompositeSubItems flattened** — recursive flattening in `OrderItemResponse::toDomain()` with `parentItemId` reference

### Testing
- Updated 6 existing use case test mocks (saveOrdersBulk -> saveMany)
- All 2760 tests pass
