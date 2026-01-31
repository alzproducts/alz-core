# Fix: Mixpanel Order Lookup Table Duplicate Hash Error

## Problem Summary

`SyncOrderLookupTableJob` fails with HTTP 400 "invalid row" because duplicate `order_id_hashed` values are sent to Mixpanel. This occurs when edited orders share the same `reference` (the cancelled original + its replacement both generate the same hash).

**Root cause:** `OrderLookupTableProvider::buildQuery()` doesn't deduplicate orders by reference.

---

## Solution: PostgreSQL View for Order Deduplication

Create a reusable view `shopwired.orders_deduplicated` that applies `DISTINCT ON (reference)` with proper ordering, ensuring only the canonical (non-cancelled, newest) order per reference is returned.

---

## Implementation Plan

### Task 1: Create Expression Index Migration

**File:** `database/migrations/2026_01_30_XXXXXX_add_orders_deduplication_index_shopwired.php`

```php
DB::statement("
    CREATE INDEX IF NOT EXISTS idx_orders_reference_dedup
    ON shopwired.orders (
        reference,
        (CASE WHEN lifecycle_status IN ('cancelled', 'refunded') THEN 1 ELSE 0 END),
        external_id DESC
    )
");
```

**Notes:**
- No `CONCURRENTLY` - it cannot run inside Laravel's migration transaction, and regular index creation is fast enough for ~70k rows (<1 sec)
- Expression index matches the ORDER BY in the view exactly

---

### Task 2: Create Deduplicated Orders View Migration

**File:** `database/migrations/2026_01_30_XXXXXX_create_orders_deduplicated_view_shopwired.php`

```php
DB::statement("
    CREATE OR REPLACE VIEW shopwired.orders_deduplicated AS
    SELECT DISTINCT ON (reference) *
    FROM shopwired.orders
    ORDER BY reference,
             CASE WHEN lifecycle_status IN ('cancelled', 'refunded') THEN 1 ELSE 0 END,
             external_id DESC
");
```

**Deduplication logic:**
1. Group by `reference`
2. Prefer non-cancelled/non-refunded orders
3. Tie-breaker: highest `external_id` (newest)

---

### Task 3: Update OrderLookupTableProvider

**File:** `app/Infrastructure/Mixpanel/LookupTables/OrderLookupTableProvider.php`

Change `buildQuery()` to use the view:

```php
private function buildQuery(): string
{
    return <<<'SQL'
        SELECT
            o.reference,
            o.order_placed_at,
            -- ... existing columns ...
        FROM shopwired.orders_deduplicated o  -- Changed from shopwired.orders
        JOIN shopwired.customers c ON c.external_id = o.customer_id
        SQL;
}
```

---

### Task 4: Refactor EloquentOrderRepository

**File:** `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php`

Simplify `getOrdersInDateRange()` by using the view instead of inline `DISTINCT ON`:

```php
public function getOrdersInDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array
{
    return $this->eloquentGateway->query(static function () use ($from, $to): array {
        $query = self::MODEL_CLASS::query()
            ->from('shopwired.orders_deduplicated')  // Use view
            ->whereBetween('order_placed_at', [$from, $to])
            ->with(self::EAGER_LOAD_RELATIONS)
            ->orderBy('order_placed_at');

        // ... email exclusion logic unchanged ...

        return /* mapped results */;
    });
}
```

**Keep `selectPreferredOrder()`** - it's still used by `getByReference()`. Add a doc comment explaining why:

```php
/**
 * Select the preferred order from a collection sharing the same reference.
 *
 * Note: This method is kept for getByReference() which queries the raw orders table
 * for performance (single-reference lookup is faster than view). For bulk queries,
 * use the shopwired.orders_deduplicated view instead.
 *
 * @see shopwired.orders_deduplicated - View-based deduplication for bulk queries
 */
private static function selectPreferredOrder(Collection $orders): OrderModel
```

---

### Task 5: Add Documentation to SyncOrdersToMixpanelJob

**File:** `app/Presentation/Jobs/Mixpanel/SyncOrdersToMixpanelJob.php`

Add docblock explaining required data and common failures:

```php
/**
 * Sync historical ShopWired orders to Mixpanel as checkout/product events.
 *
 * Scheduled to run daily at 2:00 AM Europe/London with 24-hour lookback.
 * Uses pre-export deduplication to prevent duplicate events.
 *
 * ## Required Data
 *
 * 1. **shopwired.orders** — Orders in the date range (with products, discounts, refunds)
 * 2. **shopwired.customers** — Customer `is_trade` status for each order's customer
 * 3. **Mixpanel Export API** — Existing order hashes for deduplication
 *
 * ## Common Failure: MissingRequiredDataException
 *
 * If customers referenced by orders don't exist in `shopwired.customers`, the job fails.
 * This happens when new customers placed orders but haven't been synced yet.
 *
 * **Resolution:** Run a customer sync first, then retry this job.
 * - Quick sync (ALL trade + recent non-trade): `SyncShopwiredCustomersJob::dispatch(null, 5)`
 * - Full sync (all ~68k customers, ~45 min): `SyncShopwiredCustomersJob::dispatch()`
 *
 * Quick sync is usually sufficient since trade customers (~466) fit in ~5 pages,
 * so `maxTradePages=null` fetches 100% of trade accounts.
 */
```

**Note:** Updated from the user's draft to use `dispatch(null, 5)` which correctly fetches ALL trade pages (null = unlimited) + 5 non-trade pages.

---

### Task 6: Update Database CLAUDE.md

**File:** `database/CLAUDE.md`

Add section about orders deduplication:

```markdown
## Order Deduplication

When orders are "edited" in ShopWired, a new order is created with the same `reference` but different `external_id`. The original is cancelled.

**For queries needing one order per reference:**
- Use `shopwired.orders_deduplicated` view (preferred)
- This view applies `DISTINCT ON (reference)` with proper ordering

**For audit/history queries needing all orders:**
- Use `shopwired.orders` table directly
```

---

### Task 7: Add Doc Comment to OrderCustomer About Guest Orders

**File:** `app/Domain/Catalog/Order/ValueObjects/OrderCustomer.php`

Add a doc comment explaining the legacy guest order situation:

```php
/**
 * Customer associated with an order.
 *
 * ## Guest Orders (Legacy)
 *
 * ShopWired returns `customerId: 0` for guest checkouts (no customer account).
 * This is a **legacy data issue only** — older orders from before customer tracking
 * may have customer_id = 0 or NULL.
 *
 * **Current behavior:** Guest orders are no longer created; all checkouts require
 * a customer account. The assertion `$id > 0` will fail for legacy guest orders
 * if they're ever processed.
 *
 * **If processing legacy orders:** Handle the guest case in Infrastructure layer
 * before constructing this value object (e.g., skip the order or use a placeholder).
 */
final readonly class OrderCustomer
```

---

### Task 8: Update SyncShopwiredCustomersJob Docblock

**File:** `app/Presentation/Jobs/Shopwired/SyncShopwiredCustomersJob.php`

Update the usage examples to recommend `dispatch(null, 5)` for quick sync:

```php
/**
 * Usage:
 * - Full sync: SyncShopwiredCustomersJob::dispatch() — daily, ~45 min
 * - Quick sync: SyncShopwiredCustomersJob::dispatch(null, 5) — ALL trade + 5 non-trade pages, ~2 min
 * - Micro sync: SyncShopwiredCustomersJob::dispatch(1, 1) — every 5 min, ~30s
 *
 * Quick sync uses null for maxTradePages to guarantee 100% trade customer coverage,
 * regardless of how many trade customers exist (currently ~466, fits in ~5 pages).
 */
```

---

## Files to Modify

| File | Change |
|------|--------|
| `database/migrations/2026_01_30_*_add_orders_deduplication_index_shopwired.php` | NEW |
| `database/migrations/2026_01_30_*_create_orders_deduplicated_view_shopwired.php` | NEW |
| `app/Infrastructure/Mixpanel/LookupTables/OrderLookupTableProvider.php` | Update query |
| `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php` | Refactor method + add doc comment |
| `app/Presentation/Jobs/Mixpanel/SyncOrdersToMixpanelJob.php` | Add docblock |
| `database/CLAUDE.md` | Add documentation |
| `app/Presentation/Jobs/Shopwired/SyncShopwiredCustomersJob.php` | Update quick sync recommendation |
| `app/Domain/Catalog/Order/ValueObjects/OrderCustomer.php` | Add doc comment about legacy guest orders |

---

## Verification

1. **Run migrations locally:**
   ```bash
   php artisan migrate
   ```

2. **Verify view returns deduplicated rows:**
   ```bash
   php artisan tinker --execute="
   \$total = DB::selectOne('SELECT COUNT(*) as cnt FROM shopwired.orders')->cnt;
   \$deduped = DB::selectOne('SELECT COUNT(*) as cnt FROM shopwired.orders_deduplicated')->cnt;
   echo \"Orders: \$total, Deduplicated: \$deduped, Removed: \" . (\$total - \$deduped);
   "
   ```

3. **Run the job locally:**
   ```bash
   php artisan tinker --execute="
   App\Presentation\Jobs\Mixpanel\SyncOrderLookupTableJob::dispatchSync();
   echo 'Success!';
   "
   ```

4. **Run tests:**
   ```bash
   make test
   ```

---

## Trade Customer Investigation Result

**Finding:** Current quick sync design is correct.

| Metric | Value |
|--------|-------|
| Trade customers | 466 |
| Pages needed (100/page) | ~5 |
| Quick sync trade pages | null (unlimited) |
| Trade coverage in quick sync | **100%** |

The `dispatch(null, 5)` pattern fetches ALL trade customers (since they fit in ~5 pages) while limiting non-trade to 5 pages. This is intentional and optimal - no changes needed to sync logic.
