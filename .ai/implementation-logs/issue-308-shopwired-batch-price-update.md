# Implementation Log: ShopWired Batch Price Update

**GitHub Issue**: #308
**Plan Document**: .ai/plans/2026-03-17_308-shopwired-batch-price-update.md
**Status**: In Progress
**Started**: 2026-03-19
**Completed**: —

## Overview

Migrate price updates from PUT endpoint to `POST /v1/products/prices` batch endpoint with SCD2 price history tracking. Part 1 (PUT cleanup) and Part 2 (batch API + use case) are complete. Part 3 (SCD2 + events) is next.

## Decision Log

### 2026-03-19 — Refactor: Clean up typed results
- **Decision**: Renamed `SkippedPriceUpdate` / `FailedPriceUpdate` to `SkippedPriceUpdateResult` / `FailedPriceUpdateResult`
- **Why**: PHPArkitect enforces `*Result` suffix for classes in Application layer `Results/` directories
- **Tradeoff**: Slightly more verbose names, but consistent with project naming conventions

- **Decision**: Used explicit null guard (`if ($apiResult === null)`) in `PriceUpdateResult::fromPhases()` instead of nullsafe+coalesce (`?->` + `??`)
- **Why**: PHPStan ShipMonk rules flag `?->prop ?? default` as `nullsafe.neverNull`. The explicit branch is also more readable — each path constructs a complete object.
- **Tradeoff**: More lines of code, but PHPStan can narrow types cleanly in each branch

- **Decision**: `FailedPriceUpdateResult::$sku` is `?Sku` (nullable) rather than a string default
- **Why**: Chunk-level API failures (PartialBatchFailureException) can't attribute to a specific SKU. `null` forces callers to handle the gap explicitly via type system.

## Deviations from Plan

- Plan used `SkippedPriceUpdate` / `FailedPriceUpdate` names; renamed to `*Result` suffix for PHPArkitect compliance
- Plan's `fromPhases()` used nullsafe chaining; switched to explicit null guard for PHPStan compliance

## Blockers / Open Questions

- [ ] Part 3: SCD2 price history table + event listeners
- [ ] Part 3: Slack notifications on ProductPricingUpdatedEvent
- [ ] Tests for UpdateProductPricesUseCase (deferred to Part 3)

## PR Notes

### What
Refactored `UpdateProductPricesUseCase` internal types: replaced anonymous array shapes (`list<array{sku: string, error: string}>`) with typed result objects, extracted single-command validation, added `PriceUpdateResult::fromPhases()` factory.

### Why
Array shapes appeared 10+ times across 4 files, breaking the project's VO culture. The `'sku' => 'unknown'` magic string for chunk-level failures lacked type safety.

### Key Decisions
- `FailedPriceUpdateResult(?Sku)` models chunk-level gap explicitly via null
- `validateSingleCommand()` separates per-item validation from batch coordination
- `fromPhases()` eliminates manual failure merging in the orchestrator
