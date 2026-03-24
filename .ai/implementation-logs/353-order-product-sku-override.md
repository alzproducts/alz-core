# Implementation Log: #353 Order Product SKU Override

**GitHub Issue**: #353
**Plan Document**: `.ai/plans/2026-03-24_353-order-product-sku-override.md`
**Status**: In Progress
**Started**: 2026-03-24

## Overview

Create `order_product_extra_data` table for manual SKU overrides, resolve overrides transparently via a database view, and add Mixpanel empty-SKU skip guard to prevent Sentry ALZ-CORE-3Z crashes.

## Decision Log

### 2026-03-24
- **Decision**: Use SHA-256 (truncated to 32 chars) instead of MD5 for variation_hash
- **Why**: PHPStan's `disallowed.function` rule bans `md5()`. SHA-256 substr(0,32) satisfies the linter while keeping VARCHAR(32) column size.

- **Decision**: Catch `JsonException` in `computeVariationHash()` and throw `InvalidArgumentException`
- **Why**: `JsonException` is a checked exception in ShipMonk config. Propagating `@throws JsonException` cascades through the entire call chain. Since encoding typed string arrays is a programming contract (should never fail), `InvalidArgumentException` is semantically correct and is in the unchecked exceptions list.

- **Decision**: Exclude `OrderProductExtraDataModel` from `alz.shopwiredModelMustImplementMappable` via phpstan.neon
- **Why**: Model is Infrastructure-only metadata with no Domain counterpart. User approved exclusion over implementing a dummy interface.

- **Decision**: Use `getBaseQuery()->from()` instead of `->from()` on HasMany
- **Why**: Larastan declares `Builder::from()` as static, causing `staticMethod.dynamicCall`. Going through `getBaseQuery()` targets the Query\Builder where `from()` is an instance method.

- **Decision**: Filter empty-SKU products early in `fromOrder()` instead of in `buildCart()`
- **Why**: Sweep found that `itemCount`/`totalQuantity` were computed from unfiltered products while `buildCart()` filtered them, causing data inconsistency. Moving filter before all metric computation ensures consistency.

- **Decision**: Keep COALESCE form in view JOIN (not IS NULL form)
- **Why**: Aligns with the COALESCE-based functional unique index on `order_product_extra_data`, allowing PostgreSQL to leverage the index directly.

- **Decision**: Drop `order_id` (UUID FK) from `order_product_extra_data` table
- **Why**: Extra data is permanent manual data, matched by ShopWired external IDs (stable across syncs). No need for database-level FK to internal UUID.

- **Decision**: Move empty-SKU filtering from Infrastructure (MixpanelClient/DTOs) to Application (UseCase)
- **Why**: "Skip orders with bad data quality" is a business rule, not an Infrastructure concern. UseCase is the right gatekeeper. DTOs keep their assertions as hard-fail safety nets.

- **Decision**: Create `ErrorReporterInterface` in Application, implemented by `SentryErrorReporter` in Infrastructure
- **Why**: UseCase needs to report to Sentry but can't depend on Sentry SDK directly. Follows Dependency Inversion — Application defines the contract, Infrastructure implements it.

- **Decision**: Skip ENTIRE order when any product has empty SKU (not just the product)
- **Why**: Sending a partial "Checkout Completed" event with mismatched itemCount/totalQuantity would corrupt analytics. All-or-nothing is the only safe approach.

## Deviations from Plan

- Hash algorithm changed from MD5 to SHA-256 (truncated) — linter requirement
- `computeVariationHash()` wraps JsonException in InvalidArgumentException — checked exception cascade prevention
- Empty-SKU filter moved from `buildCart()` to `fromOrder()` — sweep found itemCount/totalQuantity inconsistency
- Empty-SKU filter moved from Infrastructure (MixpanelClient + DTO) to Application (UseCase) — business rule placement
- `order_id` column dropped from `order_product_extra_data` — extra data matched on external IDs only
- New `ErrorReporterInterface` + `SentryErrorReporter` for UseCase → Sentry reporting

## Files Changed

| File | Change |
|------|--------|
| `database/migrations/2026_03_24_110000_*.php` | Add `variation_hash` to order_products + backfill |
| `database/migrations/2026_03_24_120000_*.php` | Create `order_product_extra_data` table |
| `database/migrations/2026_03_24_130000_*.php` | Create `order_products_resolved` view |
| `app/Infrastructure/Shopwired/Models/OrderProductModel.php` | Add `computeVariationHash()`, update `fromDomainAttributes()` |
| `app/Infrastructure/Shopwired/Models/OrderProductExtraDataModel.php` | **New** — Infrastructure-only Eloquent model |
| `app/Infrastructure/Shopwired/Models/OrderModel.php` | Update `products()` to read from view via `getBaseQuery()->from()` |
| `app/Infrastructure/Mixpanel/MixpanelClient.php` | Reverted empty-SKU skip guard (UseCase now handles filtering) |
| `app/Infrastructure/Mixpanel/DTOs/MixpanelCheckoutCompletedDTO.php` | Reverted productsWithSku filter (UseCase guarantees no empty SKUs) |
| `app/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCase.php` | Inject ErrorReporter, filter empty-SKU orders before import |
| `app/Application/Contracts/ErrorReporterInterface.php` | **New** — Generic error reporting contract |
| `app/Infrastructure/ErrorReporting/SentryErrorReporter.php` | **New** — Sentry implementation of ErrorReporterInterface |
| `app/Providers/AppServiceProvider.php` | Bind ErrorReporterInterface → SentryErrorReporter |
| `app/Providers/MixpanelServiceProvider.php` | Inject ErrorReporterInterface into UseCase |
| `tests/.../SyncOrdersToMixpanelUseCaseTest.php` | Add empty-SKU filtering tests, mock ErrorReporterInterface |
| `phpstan.neon` | Exclude OrderProductExtraDataModel from mappable rule |

## Lint Fixes

- `md5()` → `hash('sha256', ...)` with substr (disallowed.function)
- `->from()` → `getBaseQuery()->from()` (staticMethod.dynamicCall)
- `JsonException` → `InvalidArgumentException` wrapping (checked exception cascade)
- phpstan.neon exclusion for OrderProductExtraDataModel (custom rule)

## Simplify Fixes

- Fixed stale docblock — "MD5 hash" → "SHA-256 hash (first 32 hex chars)"
- Eliminated double `array_map` — extracted `$variationArrays` local variable
- Added maintenance comment on explicit view column list

## Sweep Fixes

- Fixed `itemCount`/`totalQuantity` inconsistency — filter empty-SKU products before computing any metrics in `fromOrder()`
- Reverted view JOIN to COALESCE form to align with functional unique index

## Test Results

- All 2587 tests pass (1 pre-existing skip)
- Linters pending (stop hooks will run)

## PR Notes

### What
Add `order_product_extra_data` table for manual SKU overrides, a transparent `order_products_resolved` database view, and a Mixpanel empty-SKU skip guard.

### Why
Orders from ShopWired occasionally arrive without SKUs (Sentry ALZ-CORE-3Z), crashing the Mixpanel sync pipeline. The override table lets operators manually correct SKUs, the view resolves overrides transparently, and the skip guard prevents crashes while corrections are pending.

### Key Decisions
- Database view pattern (`COALESCE(sku_override, sku)`) — invisible to all consumers
- SHA-256 truncated to 32 chars for variation_hash (linter requires `hash()` over `md5()`)
- `InvalidArgumentException` wrapping for `JsonException` to prevent checked exception cascade
- Infrastructure-only model excluded from domain mapping PHPStan rule

### Testing
- All 2585 existing tests pass
- Manual verification: run migrations, insert test override via Supabase Studio, verify view resolution
