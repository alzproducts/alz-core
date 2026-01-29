# Plan: ShopWired Order Audit Command + Reorganization

## Summary

1. Rename `ShopwiredAuditSyncCommand` → `ShopwiredAuditProductSyncCommand`
2. Move both commands to `Commands/Shopwired/` namespace
3. Create new `ShopwiredAuditOrderSyncCommand` for order/line auditing

---

## Phase 1: Rename & Move Product Audit Command

### File Changes

| From | To |
|------|-----|
| `Commands/ShopwiredAuditSyncCommand.php` | `Commands/Shopwired/ShopwiredAuditProductSyncCommand.php` |

### Code Changes

1. Update namespace: `App\Presentation\Console\Commands\Shopwired`
2. Rename class: `ShopwiredAuditProductSyncCommand`
3. Update signature: `shopwired:audit-product-sync`

---

## Phase 2: Create Order Audit Command

### File

`app/Presentation/Console/Commands/Shopwired/ShopwiredAuditOrderSyncCommand.php`

### Signature

```php
protected $signature = 'shopwired:audit-order-sync
                        {--from= : Start date (Y-m-d), defaults to 30 days ago}
                        {--to= : End date (Y-m-d), defaults to today}
                        {--show-missing : Show IDs of missing orders/lines}
                        {--limit=20 : Limit number of missing items shown}';
```

### Dependencies

```php
public function handle(
    OrderClientInterface $orderClient,
    OrderRepositoryInterface $orderRepository,
): int
```

### Logic Flow

1. Parse `--from` and `--to` dates (default: last 30 days)
2. Fetch orders from API: `$orderClient->listOrdersInRangeWithDetails($from, $to)`
3. Extract API counts:
   - Order IDs (external_id)
   - Order line count (sum of all `$order->products`)
4. Fetch orders from DB: `$orderRepository->getOrdersInDateRange($from, $to)`
5. Extract DB counts:
   - Order IDs
   - Order line count
6. Display comparison table
7. Show missing/extra items if `--show-missing`

### Output Format

```
Auditing orders from 2025-01-01 to 2025-01-28...

+----------+--------+-------------+
| Source   | Orders | Order Lines |
+----------+--------+-------------+
| API      | 150    | 423         |
| Database | 148    | 419         |
| Diff     | +2     | +4          |
+----------+--------+-------------+

Missing from database:
  Orders: 2
  Order Lines: 4

Missing Order IDs (first 20):
  - 123456 | Ref: 78901 | 2025-01-15 | £45.99
  - 123457 | Ref: 78902 | 2025-01-16 | £32.50
```

---

## Commit Strategy

1. `refactor(shopwired): Rename and move product audit command to Shopwired namespace`
2. `feat(shopwired): Add order sync audit command with date range support`

---

## Verification

```bash
# Test product audit (renamed)
php artisan shopwired:audit-product-sync

# Test order audit with defaults (last 30 days)
php artisan shopwired:audit-order-sync

# Test order audit with specific dates
php artisan shopwired:audit-order-sync --from=2025-01-01 --to=2025-01-28

# Test with show-missing flag
php artisan shopwired:audit-order-sync --from=2025-01-01 --to=2025-01-28 --show-missing
```

---

## Files to Create/Modify

| Action | File |
|--------|------|
| Move+Rename | `Commands/ShopwiredAuditSyncCommand.php` → `Commands/Shopwired/ShopwiredAuditProductSyncCommand.php` |
| Create | `Commands/Shopwired/ShopwiredAuditOrderSyncCommand.php` |
