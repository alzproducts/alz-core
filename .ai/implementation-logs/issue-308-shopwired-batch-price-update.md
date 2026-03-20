# Implementation Log: ShopWired Batch Price Update

**GitHub Issue**: #308
**Plan Document**: .ai/plans/2026-03-17_308-shopwired-batch-price-update.md
**Status**: In Progress
**Started**: 2026-03-19
**Completed**: —

## Overview

Migrate price updates from PUT endpoint to `POST /v1/products/prices` batch endpoint with SCD2 price history tracking. Part 1 (PUT cleanup) and Part 2 (batch API + use case) are complete. Part 3 (SCD2 + Slack) is complete. Tests remain.

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

### 2026-03-19 — Part 3: Transport failure handling
- **Decision**: Removed `throw $clientResult->transportFailures[0]` from UseCase — all failures now flow through result object
- **Why**: UseCase was making a Job-layer retry decision and discarding other failures. The Job should decide.

### 2026-03-19 — Part 3: EventServiceProvider
- **Decision**: Created centralised `EventServiceProvider` for all event→listener wiring
- **Why**: Feature providers with `boot()` Event::listen calls can't be deferred. ContactSubmissionServiceProvider was already deferred but had event wiring in boot() — latent bug.
- **Tradeoff**: Existing listeners consolidated in one commit (InventoryServiceProvider, ContactSubmissionServiceProvider, NotificationServiceProvider removed/simplified)

### 2026-03-19 — Part 3: Repository interface @throws
- **Decision**: Repository interfaces must declare all three `DatabaseGateway` exceptions
- **Why**: PHPStan can't verify interface @throws covers implementation. Under-declared interfaces cause silent exception propagation gaps.
- **Tradeoff**: Created issue #311 for full audit. Added guidance to Application/CLAUDE.md.

### 2026-03-19 — Part 3: RecordPricePeriodListener exception handling
- **Decision**: Listener uses full job-style try/catch with InteractsWithQueue (release/fail/attempts)
- **Why**: SCD2 recording is critical persistence, not fire-and-forget like Slack notifications. Needs explicit transient retry via release() and permanent fail().

### 2026-03-19 — Part 3: ProductPricingUpdatedEvent enrichment
- **Decision**: Replaced `list<Sku> $updatedSkus` with `list<SkuPriceChange> $priceChanges`
- **Why**: Slack notification needs price data. SkuPriceChange VO carries previous/new pricing + sale transition helpers (addedToSale/removedFromSale/saleChanged).

### 2026-03-19 — Part 3: PricePeriodRepository scalars
- **Decision**: Repository interface accepts scalar values (float, string, bool), not ProductRetailPricing
- **Why**: Repository is a persistence boundary — UseCase decomposes the domain VO into scalars.

### 2026-03-19 — ChatNotificationInterface (#312)
- **Decision**: Created `ChatNotificationInterface` with domain-data-in, framework-out design
- **Why**: All 5 Slack listeners duplicated config lookup, channel guard, and `Notification::route()`. The `object` type hack was rejected — interface accepts typed domain data per method, Infrastructure handles all Laravel mechanics.
- **Tradeoff**: Interface grows a method per notification type. Honest about what's happening.

- **Decision**: `InvalidConfigurationException` on missing channel config (fail-fast) instead of silent skip
- **Why**: Matches project's Providers/CLAUDE.md fail-fast philosophy. Misconfigured channels should surface immediately.

- **Decision**: Enriched `ProductPricingUpdatedSlackListener` with product title + URL via `ProductRepositoryInterface::getProduct()`
- **Why**: Notification was showing only product ID (internal to ShopWired). Product name + link button is actionable for the team.
- **Tradeoff**: Enrichment is best-effort (`@ignoreException`) — notification still sends without title if repo lookup fails.

## Deviations from Plan

- Plan used `SkippedPriceUpdate` / `FailedPriceUpdate` names; renamed to `*Result` suffix for PHPArkitect compliance
- Plan's `fromPhases()` used nullsafe chaining; switched to explicit null guard for PHPStan compliance
- Plan had UseCase throw transportFailures[0]; changed to return all failures via result object
- Plan wired events in ShopwiredServiceProvider boot(); created dedicated EventServiceProvider instead
- Plan had `list<Sku>` on ProductPricingUpdatedEvent; replaced with `list<SkuPriceChange>` for richer Slack notifications
- Plan had `ProductRetailPricing` on repository interface; replaced with scalar parameters

## Blockers / Open Questions

- [x] Part 3: SCD2 price history table + event listeners
- [x] Part 3: Slack notifications on ProductPricingUpdatedEvent
- [ ] Tests for UpdateProductPricesUseCase, RecordPricePeriodUseCase, listeners, PriceUpdateClient
- [x] Slack listener enriched with product name + URL via ProductRepositoryInterface
- [x] ChatNotificationInterface created — all 5 listeners migrated (#312)

## PR Notes

### What
- SCD2 `operations.price_periods` table with event-driven recording
- Slack notification on product pricing updates with per-SKU price changes and sale indicators
- Centralised EventServiceProvider (fixes latent deferred-provider event bug)
- PHPStan rule enforcing Event::listen in EventServiceProvider only
- Fixed transport failure handling — UseCase returns all failures via result, not throwing first

### Why
- Price history tracking for compliance and margin analysis
- Slack notifications give immediate visibility into price changes
- Deferred providers shouldn't wire events in boot() — they may never execute

### Key Decisions
- `SkuPriceChange` VO with sale transition helpers (addedToSale/removedFromSale/saleChanged)
- RecordPricePeriodListener uses full job-style exception handling (InteractsWithQueue)
- PricePeriodRepositoryInterface accepts scalars, not domain VOs (clean persistence boundary)
- Repository interfaces must declare all three DatabaseGateway exceptions (#311)
