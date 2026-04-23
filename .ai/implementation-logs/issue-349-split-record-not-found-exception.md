# Implementation Log: Split RecordNotFoundException from ResourceNotFoundException

**GitHub Issue**: #349
**Plan Document**: .ai/plans/2026-04-23_349-split-record-not-found-exception.md
**Status**: In Progress
**Started**: 2026-04-23
**Completed**: —

## Overview

Split the overloaded `ResourceNotFoundException` (used both for external API 404s and local DB row-not-found) into two siblings: `ResourceNotFoundException` stays on the permanent API-404 path, and a new `RecordNotFoundException extends TransientApiFailure` handles DB row-not-found so jobs retry instead of failing immediately. Resolves Sentry issue ALZ-CORE-5M.

## Decision Log

### 2026-04-23
- **Decision**: Create `RecordNotFoundException` as a `final` class extending `TransientApiFailure`, baking in `serviceName: 'Database'`.
- **Why**: Every DB-path throw site was `new ResourceNotFoundException('Database', ...)`. Hard-coding the service name removes a meaningless arg and aligns with how other concrete exceptions (e.g. `RateLimitException`) are structured.
- **Tradeoff**: Loses the flexibility to change the service name later, but "Database" is a concrete category, not a tunable — if DB events ever need their own service grouping, we'd prefer a bespoke exception anyway.

### 2026-04-23
- **Decision**: `$retryAfter` defaults to `null` — no call site will pass a hint in this refactor.
- **Why**: `HandleApiExceptions` already falls back to the job's own `$backoff` array when `retryAfter` is null. Introducing default backoff hints is orthogonal to this refactor and belongs in per-job tuning.

## Deviations from Plan

- **Add `RecordNotFoundException → HTTP 404` mapping in `InternalApiExceptionMapper`** (not in plan). Without it, synchronous internal-API reads against a nonexistent DB record would return `503 service_unavailable` (because `TransientApiFailure` maps to 503), a regression from the current 404. Extended `errorType()` to report `NotFound` and carved `RecordNotFoundException` out of `fixedSafeMessage()`'s TransientApiFailure case so the user-facing message stays the exception's own static "Record not found in database".
- **`GenerateVariantSkusCommand` (console) catches `RecordNotFoundException`** (not explicitly in plan). `GenerateVariantSkusUseCase::loadStandardSignVariations` calls `productRepository->getProduct()` which is now a DB-path throw. Added a specific case with a message that directs the user to sync first or retry.
- **Updated `HandleDatabaseExceptions` docblock** to reflect that DB repositories no longer throw `PermanentApiFailure` for missing records; kept the catch for safety in case a future DB path throws a permanent exception.
- **`UpdateSkuUseCase` catch list extended** to include `RecordNotFoundException`. Before the refactor the ShopWired client's DB-resolve failure threw `ResourceNotFoundException` and triggered Linnworks compensation; after the refactor it throws `RecordNotFoundException`, so adding it to the catch preserves the compensation guarantee (otherwise Linnworks would be left modified with no rollback on a local-DB race).
- **`InternalApiExceptionMapper::errorType()` split into `errorTypeFromException()` + `errorTypeFromStatus()`** to satisfy the `alz.excessiveMethodLength` 20-line rule after adding the `RecordNotFoundException` arm.

## Propagation Sweep (via PHPStan)

`make lint` flagged 24 `missingType.checkedException` / `throws.unusedType` errors for callers of the affected repositories. A subagent propagated `@throws RecordNotFoundException` across:
- Catalog use cases: `GetBrand*`, `GetCategory*`, `GetProduct*`, `SyncBestSellersCategory` (7 files)
- Shopwired use cases: `UpdateProductCategoryMembership`, `ReconcileShopwiredComparePrice`, `UpdateProductSellingPrices`, `AddProductToSale`, `ReconcileProductSaleState`, `RemoveProductFromSale`, `CreateOrderRefund`, `UpdateOrderStatus`, `UpdateProductStock` (9 files)
- Upstream callers: `ProcessContactSubmissionUseCase`, `UpdateSkuUseCase`, `UpdateProductPricesUseCase`, `CheckExpiredSalesUseCase`, `HandleOrderWebhookService`, `HandleProductWebhookService`, 4 controllers, 2 webhook controllers, `TestShopwiredCostPriceCommand`
- Stale DB-path docblocks: `EloquentContactSubmissionRepository::findById`, `BasicProductUpdateClient::{update,resolveEntity}` (and their interface siblings)
- `EloquentFilterGroupRepository::getByOptionNo` + `FilterGroupRepositoryInterface`

## Blockers / Open Questions

- [x] Verify `UpdateSkuUseCase` / `LinnworksStockItemCreatorService` mixed catches — confirmed pure-API catches (no DB reads); left untouched.
- [x] `GenerateVariantSkusCommand` — extended to handle `RecordNotFoundException` from `loadStandardSignVariations`.

## Technical Notes

- Keep static exception message per `Domain/CLAUDE.md`: dynamic data (`resourceType`, `resourceId`) exposed via `context()` for Sentry grouping.
- PHPStan's `missingType.checkedException` will drive `@throws` propagation after throw-site edits.
- Full `make test` passed (3204 tests, 7349 assertions). 6 warnings and 12 notices in `GracefulCacheTest` / `SubmitContactFormUseCase` tests are pre-existing and unrelated to this branch.

### Simplify pass (2026-04-23)
Three review agents (reuse / quality / efficiency) flagged five items; applied three, skipped two:
- **Applied Q3** — `HandleDatabaseExceptions` docblock: trimmed "kept for safety — DB repositories no longer throw this category" PR-delta narration. The catch-all exists for current behavior; the log captures the history.
- **Applied E1 (2 files)** — `ReconcileProductSaleStateJob::handle()` `@throws ResourceNotFoundException` → `@throws RecordNotFoundException` (only DB path). `UpdateLinnworksSellingPriceEpsJob::handle()` split into `@throws RecordNotFoundException When product not found in local DB` + `@throws ResourceNotFoundException When stock item not found in Linnworks` — the method hits both the local repo and the Linnworks API.
- **Skipped Q1** — widening `UpdateSkuUseCase::compensateAndRethrow`'s param to `AbstractApiException` would break PHPStan's `@throws` fidelity: `throw $originalError` would throw the wide type, so the narrow 7-entry `@throws` list either has to widen (polluting caller contracts) or lose type-system backing. The duplication between catch and signature is load-bearing.
- **Skipped Q2** — widening `GenerateVariantSkusCommand`'s union would lose PHPStan's `match(true)` exhaustiveness guard against `UnhandledMatchError`.
- **Skipped R1 / quality nit on `fixedSafeMessage()`** — the `TransientApiFailure && ! RecordNotFoundException` negated pattern mirrors the adjacent `PermanentApiFailure && ! ResourceNotFoundException` carve-out; reordering breaks the parallel.

## PR Notes

### What
Split `ResourceNotFoundException` into two siblings: an unchanged `ResourceNotFoundException` (permanent — external API 404s) and a new `RecordNotFoundException` (transient — DB row not found). All 12 DB-path throw sites, 6 Delete*UseCase catches, relevant internal-repository catches, interface `@throws` docblocks, and test stubs migrated to the new exception.

### Why
`ResourceNotFoundException` was failing jobs immediately on transient DB race conditions (e.g. `ShopwiredProductRepository::syncVariations()` delete+insert vs. concurrent `product.stock_changed`). Surfacing as Sentry ALZ-CORE-5M. By routing DB not-found to `TransientApiFailure`, `HandleApiExceptions` already releases-and-retries without any middleware change.

### Key Decisions
- New exception is `final`, hard-codes `'Database'` service name, keeps static message for Sentry grouping.
- `$retryAfter` defaults to `null` — jobs fall back to their own `$backoff` array.
- No middleware or job-config changes: `HandleDatabaseExceptions` transparently passes the transient exception through; `HandleApiExceptions` catches it.

### Testing
- Existing Delete*UseCase tests updated to stub `RecordNotFoundException`.
- New `HandleApiExceptionsTest` case: `RecordNotFoundException` takes the transient release path.
- Optional `RecordNotFoundExceptionTest` pins `context()` shape and `serviceName === 'Database'`.
