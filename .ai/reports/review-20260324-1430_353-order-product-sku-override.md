# Code Review: #353 Order Product SKU Override

**Date**: 2026-03-24
**Reviewer**: Claude Opus 4.6 (multi-model review)
**Branch**: `feature/353-order-product-sku-override`
**Scope**: 12 uncommitted files implementing SKU override table, resolved view, and Mixpanel empty-SKU guard

> This is a small business application. Recommendations prioritise proportional value.

## Executive Summary

The implementation is architecturally sound — Clean Architecture boundaries are respected, the new `ErrorReporterInterface` follows Dependency Inversion correctly, and the database view pattern for transparent SKU overrides is well-designed. However, there is one **must-fix** issue (stale staged git changes) and several improvements worth considering.

**Finding counts**: 0 Critical | 1 High | 2 Medium | 4 Low

## Findings by Severity

---

### HIGH

#### H1 — Stale staged changes in Mixpanel files will commit unintended code

- **Location**: `app/Infrastructure/Mixpanel/MixpanelClient.php` (staged), `app/Infrastructure/Mixpanel/DTOs/MixpanelCheckoutCompletedDTO.php` (staged)
- **Source**: Internal + External (Both)
- **Consensus**: Agreed
- **Confidence**: High

**Issue**: Both files show `MM` git status — staged changes add Infrastructure-level empty-SKU filtering (skip empty SKU in `buildOrderEvents()` and `buildCart()`), but the working tree reverts these changes. The implementation log confirms this filtering was intentionally moved to the UseCase layer. Committing as-is would re-introduce stale filtering code that duplicates the UseCase-level guard.

**Recommendation**: Before committing, unstage the Mixpanel files:
```bash
git restore --staged app/Infrastructure/Mixpanel/MixpanelClient.php
git restore --staged app/Infrastructure/Mixpanel/DTOs/MixpanelCheckoutCompletedDTO.php
```

---

### MEDIUM

#### M1 — Migration backfill uses string interpolation in raw SQL

- **Location**: `database/migrations/2026_03_24_110000_add_variation_hash_to_shopwired_order_products.php:34-35`
- **Source**: Internal + External (Both)
- **Consensus**: Medium (majority) — FOR model rated Critical, NEUTRAL model rated Medium
- **Confidence**: Medium-High

**Issue**: The backfill constructs a raw SQL CASE statement via string interpolation:
```php
$cases[] = "WHEN id = '{$product->id}' THEN '{$hash}'";
```

**Risk assessment**: The inputs (`$product->id` = UUID `[a-f0-9-]+`, `$hash` = SHA-256 hex `[0-9a-f]{64}`) cannot contain SQL metacharacters. The migration runs once during deployment against trusted database data. There is no practical exploit path. However, this bypasses parameterized queries and sets a poor precedent.

**Recommendation**: Use parameterized bindings via `DB::update()`:
```php
$cases[] = "WHEN id = ? THEN ?";
$bindings[] = $product->id;
$bindings[] = $hash;
```

#### M2 — `filterOrdersWithValidSkus()` passes null-products orders as valid

- **Location**: `app/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCase.php:292`
- **Source**: Internal + External (Both)
- **Consensus**: Split (FOR/NEUTRAL: High, Internal: Low → settled Medium)
- **Confidence**: Medium

**Issue**: The condition `if ($order->products === null || !self::orderHasEmptySku($order))` passes orders with `products === null` as valid. Downstream, `MixpanelCheckoutCompletedDTO::fromOrder()` asserts `notNull($order->products)`, which would crash.

**Mitigating context**: The sync pipeline loads orders in detail mode — `products` is always populated. The `?array` type on `Order::$products` exists because the Domain value object supports both standard (listing) and detail (with products) modes.

**Why still Medium**: The filter explicitly acknowledges null as a possibility by checking for it, then lets it through to crash downstream. This is an internal logical inconsistency regardless of the pipeline invariant.

**Recommendation**: Remove the null pass-through. Change line 292 to treat null products the same as empty-SKU (skip and report):
```php
if ($order->products !== null && !self::orderHasEmptySku($order)) {
```

---

### LOW

#### L1 — View LEFT JOIN overhead on every `products()` query

- **Location**: `app/Infrastructure/Shopwired/Models/OrderModel.php:148-149`
- **Source**: Internal + External (Both)
- **Consensus**: Agreed
- **Confidence**: Medium

**Issue**: `OrderModel::products()` now reads from the `order_products_resolved` view, which LEFT JOINs `order_product_extra_data` on every query. When `order_product_extra_data` is empty (most of the time), PostgreSQL's optimizer makes this essentially free (hash join on empty table). The functional index ensures efficient lookups when overrides exist.

**Recommendation**: No action needed. Monitor if query times increase after deploying. The index on `(order_external_id, external_id, COALESCE(variation_hash, ''))` should keep the JOIN efficient.

#### L2 — `fromDomainAttributes()` instantiates full Eloquent model for hash computation

- **Location**: `app/Infrastructure/Shopwired/Models/OrderProductModel.php:190`
- **Source**: Internal + External (Both)
- **Consensus**: Agreed
- **Confidence**: Medium

**Issue**: `self::computeLineItemHash(new self($attributes))` creates a full Eloquent model instance just to access attribute values for hashing. Functional but wasteful.

**Recommendation**: Accept as-is. Refactoring `computeLineItemHash` to accept raw attributes would be cleaner but violates YAGNI for a small business app. The method is also used in the migration backfill where it receives actual model instances.

#### L3 — Test phone number typo

- **Location**: `tests/Unit/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCaseTest.php:354`
- **Source**: Internal + External (Both)
- **Consensus**: Agreed
- **Confidence**: High

**Issue**: `telephone: '01onal234567890'` — appears to be a typo. "onal" in the middle of a phone number.

**Recommendation**: Fix to `'01onal234567890'` — actually, this is test data and doesn't affect functionality. Fix if it bothers you: `'01onal234567890'` → `'01234567890'`.

#### L4 — Skipped count calculation could be clearer

- **Location**: `app/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCase.php:150`
- **Source**: Internal + External (Both)
- **Consensus**: Agreed
- **Confidence**: Medium

**Issue**: `$skippedCount = \max(0, $skippedCount + \count($ordersToSync) - \count($validOrders))` is functionally correct but hard to parse at a glance.

**Recommendation**: Extract the delta for clarity:
```php
$emptySkuSkipped = \count($ordersToSync) - \count($validOrders);
$skippedCount += $emptySkuSkipped;
```

---

## Positive Findings

- **Clean Architecture compliance**: `ErrorReporterInterface` in Application/Contracts with `SentryErrorReporter` in Infrastructure is textbook Dependency Inversion
- **Business rule placement**: Empty-SKU filtering correctly moved from Infrastructure (MixpanelClient/DTOs) to Application (UseCase) — "skip bad data" is a business decision
- **Database view pattern**: `order_products_resolved` with `COALESCE(sku_override, sku)` is transparent to all consumers — excellent separation of concerns
- **View JOIN alignment**: COALESCE-based JOIN condition matches the functional unique index, enabling PostgreSQL to leverage the index directly
- **Robust deduplication**: Multi-hash matching and deterministic `$insert_id` generation ensure Mixpanel data integrity
- **Defensive assertions retained**: `MixpanelProductPurchasedDTO::Assert::notEmpty($sku)` kept as safety net even after UseCase filtering

## Decision Audit Trail

| Finding | Internal | External | Consensus | Final |
|---------|----------|----------|-----------|-------|
| H1 Stale staged changes | High | High | Agreed | **High** |
| M1 SQL interpolation | Medium | Critical | Majority Medium (neutral agreed) | **Medium** |
| M2 Null-products filter | Low | High | Split (2 High, 1 Low) | **Medium** |
| L1 View JOIN overhead | Low | Medium | Agreed Low | **Low** |
| L2 Model instantiation | Low | Medium | Agreed Low | **Low** |
| L3 Test typo | Low | Low | Agreed | **Low** |
| L4 Skipped count clarity | Low | Low | Agreed | **Low** |

## Limitations

- **External model availability**: gemini-3-pro-preview and gemini-2.5-pro were rate-limited. External review completed via gemini-2.5-flash only.
- **Consensus coverage**: The "against" stance model (gemini-2.0-flash) was rate-limited during consensus. Counter-arguments were self-provided. Confidence levels reduced accordingly for disputed findings.
- **Models used**: Internal (Claude Opus 4.6), External codereview (gemini-2.5-flash), Consensus FOR+NEUTRAL (gemini-2.5-flash)
