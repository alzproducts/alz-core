# Plan: Margin-Tier `custom_label_1` ShopWired Sync (COR-148)

**Linear**: [COR-148](https://linear.app/alzproducts/issue/COR-148/sync-margin-tier-labels-to-shopwired-custom-label-1)
**Branch (suggested)**: `cor-148-sync-margin-tier-labels-to-shopwired-custom_label_1`
**Status**: Approved — ready for implementation
**Anchors**: COR-128/129 (Best Sellers `custom_label_4` sync), COR-141 (margin min/max columns in `products_view`)
**ADR**: [docs/adr/0001-margin-tier-thresholds-single-row-config.md](../../docs/adr/0001-margin-tier-thresholds-single-row-config.md)

---

## Context

Apply four mutually-exclusive margin-tier labels to `custom_label_1` on ShopWired products based on the midpoint of `net_margin_single_unit_min` and `_max` (added by COR-141) compared against configurable thresholds. The architecture mirrors the COR-128/129 Best Sellers `custom_label_4` sync but with two structural simplifications:

1. **Classification lives in SQL, not PHP**: a single drift query computes the target tier per product via `CASE WHEN`, removing the need for a Transformer class.
2. **Single dispatch loop**: the query returns `(product_id, target_label)` tuples; the orchestrator loops once, not four times (Shape B per the grill).

## Decision Log

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | `custom_label_1` is a **single-select string** field | Confirmed by user. Same shape as `custom_label_4`. No ValueList semantics. |
| 2 | Sync **owns** `custom_label_1` post-launch | New sync is the sole writer once legacy sync is disabled. |
| 3 | Threshold storage: **single-row config table** `catalog.margin_tier_thresholds` | Chosen over the versioned-config precedent. See ADR-0001. |
| 4 | NULL margin → `4 - Unknown margin` | Explicit data-quality signal visible in ShopWired admin. |
| 5 | Negative margin → included in `1 - Low margin` | No separate loss-making tier. |
| 6 | Eligibility filter: `is_active = true` only | Inactive products retain their last label by being skipped by the drift query. |
| 7 | Schedule: daily 04:45 Europe/London | After Related Products (04:30). Same low-traffic window pattern as Best Sellers. |
| 8 | Initial thresholds: `low_max_pct = 20.00`, `standard_max_pct = 40.00` | User-specified bands `<20 / 20-39 / 40+`. |
| 9 | Implementation shape: **Shape B** — single SQL drift query, one PHP dispatch loop | One round-trip; trivial orchestrator. |
| 10 | First-deploy behaviour: **wait for natural 04:45** | ~2,500 active products dispatched overnight on first run. No boot listener, no tinker. |
| 11 | Test strategy: **no automated tests** | Verification via legacy-sync diff comparison (below). |
| 12 | Margin expression: **midpoint** `(net_margin_single_unit_min + net_margin_single_unit_max) / 2` | Inline in drift SQL, no view migration. Smooths single-outlier variations. |
| 13 | Boundary semantics: **strict less-than** with clean thresholds (20.00, 40.00) | A product at exactly 20% margin is Standard. |

## Tier Mapping

| Tier | Condition | Label |
|---|---|---|
| Low      | `margin < low_max_pct`                          | `1 - Low margin` |
| Standard | `low_max_pct <= margin < standard_max_pct`      | `2 - Standard margin` |
| High     | `margin >= standard_max_pct`                    | `3 - High margin` |
| Unknown  | `margin IS NULL` (no cost or zero price)        | `4 - Unknown margin` |

## Drift Query

```sql
WITH thresholds AS (
    SELECT low_max_pct, standard_max_pct
    FROM catalog.margin_tier_thresholds
    LIMIT 1
),
midpoints AS (
    SELECT
        pv.external_id,
        pv.custom_fields->>'custom_label_1' AS current_label,
        CASE
            WHEN pv.net_margin_single_unit_min IS NULL THEN NULL
            ELSE (pv.net_margin_single_unit_min + pv.net_margin_single_unit_max) / 2
        END AS margin_midpoint
    FROM catalog.products_view pv
    WHERE pv.is_active = true
),
classified AS (
    SELECT
        m.external_id,
        m.current_label,
        CASE
            WHEN m.margin_midpoint IS NULL               THEN '4 - Unknown margin'
            WHEN m.margin_midpoint <  t.low_max_pct      THEN '1 - Low margin'
            WHEN m.margin_midpoint <  t.standard_max_pct THEN '2 - Standard margin'
            ELSE                                              '3 - High margin'
        END AS target_label
    FROM midpoints m
    CROSS JOIN thresholds t
)
SELECT external_id, target_label
FROM classified
WHERE current_label IS DISTINCT FROM target_label;
```

**`IS DISTINCT FROM`**: NULL-safe inequality. On first run, every active product has `current_label IS NULL` and the target is always non-NULL, so every active product appears in the change set (natural backfill).

**NULL invariant**: per the COR-141 view definition, `net_margin_single_unit_min` and `_max` share the same `COALESCE(parent_margin, var_aggregate)` fallback path, so they're either both NULL or both non-NULL. Checking `_min IS NULL` alone is sufficient.

## Migration

```sql
CREATE TABLE catalog.margin_tier_thresholds (
    id               SMALLINT PRIMARY KEY DEFAULT 1,
    low_max_pct      DECIMAL(5,2) NOT NULL,
    standard_max_pct DECIMAL(5,2) NOT NULL,
    updated_at       TIMESTAMPTZ DEFAULT NOW()
);
ALTER TABLE catalog.margin_tier_thresholds
    ADD CONSTRAINT chk_margin_thresholds_single_row CHECK (id = 1),
    ADD CONSTRAINT chk_margin_thresholds_ordered    CHECK (low_max_pct < standard_max_pct);

INSERT INTO catalog.margin_tier_thresholds (id, low_max_pct, standard_max_pct)
VALUES (1, 20.00, 40.00);
```

## Files

### New

- `app/Application/Catalog/Enums/MarginTier.php` — backed enum (4 cases) + `FIELD = 'custom_label_1'`
- `app/Application/Catalog/MarginTiers/MarginTierAssignment.php` — DTO `{ IntId $productId, string $targetLabel }`
- `app/Application/Catalog/UseCases/SyncMarginTierLabelUseCase.php` — orchestrator
- `app/Application/Catalog/UseCases/SetProductMarginTierLabelUseCase.php` — per-product write; calls `ProductUpdateClientInterface::updateCustomFields` directly (bypasses `CustomFieldSubmissionValidator`, matching COR-128 precedent)
- `app/Infrastructure/Jobs/Catalog/SyncMarginTierLabelJob.php` — orchestrator job (low queue, `ShouldBeUnique` keyed by static string)
- `app/Infrastructure/Jobs/Shopwired/SetProductMarginTierLabelJob.php` — per-product job (bulk queue, `ShouldBeUnique` keyed by product ID, `ServiceRateLimiter::shopwiredApiBulk()` + `ServiceCircuitBreaker::shopwired()` + `HandleApiExceptions`)
- Migration: `create_catalog_margin_tier_thresholds_table`

### Modified

- `app/Application/Contracts/Catalog/ProductViewQueryRepositoryInterface.php` — add `findMarginTierDrift(): list<MarginTierAssignment>`
- `app/Infrastructure/Catalog/Repositories/ProductViewQueryRepository.php` — implement drift query
- `app/Application/Contracts/Shopwired/ShopwiredSyncDispatcherInterface.php` — add `dispatchMarginTierLabelUpdate(IntId, string)` (note: never null — every eligible product always has a target label)
- `app/Infrastructure/Shopwired/Dispatchers/QueuedShopwiredSyncDispatcher.php` — implement
- `app/Providers/Schedule/CatalogScheduleServiceProvider.php` — register `registerMarginTierLabelSchedule()` (04:45 daily, Europe/London, `withoutOverlapping(30)`, `onOneServer()`)

## Job Tunables (mirror Best Sellers precedent)

### `SyncMarginTierLabelJob`
- Queue: `low`
- `tries = 3`, `maxExceptions = 2`, `timeout = 120`, `uniqueFor = 3600`, `backoff = [30, 60]`
- `retryUntil(): now()->addMinutes(45)`
- Middleware: `HandleDatabaseExceptions`

### `SetProductMarginTierLabelJob`
- Queue: `bulk`
- `tries = 6`, `maxExceptions = 3`, `timeout = 60`, `failOnTimeout = true`, `backoff = [60, 300, 900]`
- `retryUntil(): now()->addHours(4)`
- Middleware: `ServiceRateLimiter::shopwiredApiBulk()`, `ServiceCircuitBreaker::shopwired()`, `HandleApiExceptions`
- `uniqueId(): 'set-margin-tier-label-' . $this->productId->value`

## Logging

Per-tier dispatch counts (ops monitoring signal — a spike in `dispatched_unknown` would flag missing cost data):

```php
$this->logger->info('SyncMarginTierLabel: dispatched label updates', [
    'dispatched_low'      => $countByTier['1 - Low margin']      ?? 0,
    'dispatched_standard' => $countByTier['2 - Standard margin'] ?? 0,
    'dispatched_high'     => $countByTier['3 - High margin']     ?? 0,
    'dispatched_unknown'  => $countByTier['4 - Unknown margin']  ?? 0,
]);
```

## Verification Strategy (replaces automated tests)

A legacy sync currently writes to `custom_label_1` in production. This gives us a stronger verification opportunity than unit tests for this bug class:

1. **Pull live data locally** — sync `shopwired.products.custom_fields` from prod into local DB.
2. **Run the new sync locally** in dry-run / log-only mode (instrumented to log target_label per product without dispatching).
3. **Diff comparison**:
   - **Small diff** → expected drift between legacy and new logic. Inspect each. Approve for launch.
   - **Large diff** → red flag. Likely a logic, column, or threshold bug. Investigate before launch.
4. **Cutover at launch** — deploy new sync + disable legacy sync simultaneously. New sync becomes sole owner.

This catches the same class of silent-write bug COR-129 fixed (array vs string mismatch), because a payload-format bug would produce zero successful updates → diff stays huge after first cycle → red flag.

**The PR must include a documented smoke-test checklist matching these steps.**

## Cutover

- Legacy `custom_label_1` margin sync is **out of scope** for this issue — user will locate and disable it separately.
- Plan: deploy this feature + disable legacy simultaneously at launch.
- First-deploy behaviour in this codebase: wait for the natural 04:45 schedule. No boot listener, no manual tinker, no special backfill path.

## Service Provider Bindings

`SyncMarginTierLabelUseCase` and `SetProductMarginTierLabelUseCase` have **no scalar constructor parameters** — all deps are interface-typed (`ProductViewQueryRepositoryInterface`, `ShopwiredSyncDispatcherInterface`, `ProductUpdateClientInterface`, `LoggerInterface`) and already bound. Laravel's container auto-resolves; **no new provider bindings needed**.

## PR Notes Draft

### What
Adds a daily sync that assigns one of four margin-tier labels (`1 - Low / 2 - Standard / 3 - High / 4 - Unknown`) to `custom_label_1` on each ShopWired product, based on `(net_margin_single_unit_min + net_margin_single_unit_max) / 2` versus thresholds stored in a new single-row config table (`catalog.margin_tier_thresholds`).

### Why
Gives merchandising a single-glance view of margin health per product directly in the ShopWired admin without needing to consult BI tools. Replaces a legacy sync (disabled simultaneously) with a Clean Architecture implementation that mirrors COR-128 Best Sellers, with the structural simplification of computing tier classification in SQL rather than PHP.

### Key Decisions
- Single SQL drift query (Shape B over Shape A) — one round-trip, single PHP dispatch loop
- Midpoint of min/max margin (not `_min` alone, not true `AVG()`) — smooths single-outlier variations without requiring a new view column
- Single-row config table for thresholds (not versioned config) — see ADR-0001
- No automated tests — verification by diff against legacy sync output before launch

### Testing
- Smoke-test checklist in PR description (see Verification Strategy in the plan doc)
- After-deploy verification: tinker-dispatch one product, eyeball ShopWired admin, then let the 04:45 schedule fire on the full catalog
- Diff size from first run vs current `custom_label_1` distribution is the primary correctness signal
