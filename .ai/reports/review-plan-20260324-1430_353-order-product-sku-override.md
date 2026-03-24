# Implementation Plan: Review Fixes for #353

**Review report**: `.ai/reports/review-20260324-1430_353-order-product-sku-override.md`
**Branch**: `feature/353-order-product-sku-override`

## Accepted Findings (5)

### 1. H1 — Unstage stale Mixpanel files [High]

- **File**: `app/Infrastructure/Mixpanel/MixpanelClient.php`, `app/Infrastructure/Mixpanel/DTOs/MixpanelCheckoutCompletedDTO.php`
- **Fix**: Run `git restore --staged` on both files to remove stale staged changes
- **Why**: Staged changes add Infrastructure-level empty-SKU filtering that was intentionally moved to the UseCase. Committing as-is would re-introduce duplicate filtering code.

### 2. M1 — Parameterize migration backfill SQL [Medium]

- **File**: `database/migrations/2026_03_24_110000_add_variation_hash_to_shopwired_order_products.php:28-46`
- **Fix**: Replace string interpolation with `DB::update()` using `?` parameter bindings for the CASE statement. Build `$bindings` array with `[$product->id, $hash]` pairs, plus the WHERE IN ids.
- **Why**: Eliminates SQL string interpolation pattern even though inputs are provably safe. Prevents setting a poor precedent.

### 3. M2 — Fix null-products filter logic [Medium]

- **File**: `app/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCase.php:292`
- **Fix**: Change condition from `if ($order->products === null || !self::orderHasEmptySku($order))` to filter OUT null-products orders instead of passing them through. Simplest: `if ($order->products !== null && !self::orderHasEmptySku($order))`. Null-products orders should be skipped and reported like empty-SKU orders.
- **Why**: Aligns the filter with the downstream `Assert::notNull($order->products)` in `MixpanelCheckoutCompletedDTO::fromOrder()`. Eliminates a logical inconsistency.

### 4. L3 — Fix test phone number typo [Low]

- **File**: `tests/Unit/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCaseTest.php:354`
- **Fix**: Change `'01onal234567890'` to `'01234567890'`
- **Why**: Typo in test data.

### 5. L4 — Improve skipped count readability [Low]

- **File**: `app/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCase.php:150`
- **Fix**: Extract delta into named variable:
  ```php
  $emptySkuSkipped = \count($ordersToSync) - \count($validOrders);
  $skippedCount += $emptySkuSkipped;
  ```
- **Why**: Clearer intent than the single-line `\max(0, ...)` formula.

## Implementation Order

1. **H1** first (git operation, no code change)
2. **M2** (UseCase logic fix — most impactful code change)
3. **M1** (migration fix — isolated, no downstream effects)
4. **L4** (same file as M2, do together)
5. **L3** (test file fix)

## Rejected Findings

- **L1** (View JOIN overhead): No action — PostgreSQL handles efficiently
- **L2** (Model instantiation for hash): YAGNI — current approach works fine
