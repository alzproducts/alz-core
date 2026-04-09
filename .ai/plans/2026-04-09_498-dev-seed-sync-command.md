# Plan: `dev:seed-sync` Console Command

## Context

After running `php artisan migrate` on a fresh local database, all tables are empty. Populating them currently requires manually dispatching 5+ jobs via tinker. This command automates that into a single artisan command, restricted to local environment only.

## New File

`app/Presentation/Console/Commands/Dev/SeedLocalDatabaseCommand.php`

> **Why `Dev/`?** Deptrac separates `PresentationDev` from `Presentation`. Only `PresentationDev` (classes under `Commands/Dev/`) is allowed to import `Infrastructure` job classes. The two existing dev commands (`TestPriceUpdateCommand`, `TestSlackNotificationCommand`) establish this pattern.

## Signature

```
dev:seed-sync {--incl-pii : Also dispatch PII-containing sync jobs (customers, orders)} {--pii-only : Only dispatch PII jobs, skip core} {--dry-run : Show what would be dispatched without dispatching}
```

## Environment Guard

Top of `handle()` — first local-only command in the project:

```php
if (!app()->environment('local')) {
    $this->error('This command can only run in the local environment.');
    return self::FAILURE;
}
```

## Job Tiers

### Core (always dispatched) — zero-arg, no PII

| # | Job | Data |
|---|-----|------|
| 1 | `SyncShopwiredBrandsJob` | ~30 brands |
| 2 | `SyncShopwiredCategoriesJob` | ~50 categories |
| 3 | `SyncShopwiredCustomFieldsJob` | ~100-150 field defs |
| 4 | `SyncShopwiredFilterGroupsJob` | ~10-20 filter groups |
| 5 | `SyncLinnworksSuppliersJob` | supplier list |
| 6 | `SyncShopwiredProductsJob` | ~1,500 products |
| 7 | `SyncLinnworksStockItemsJob` | ~10k stock items |
| 8 | `SyncProductRatingsJob` | product ratings |
| 9 | `SyncFastPurchaseOrdersJob` | open/pending/recent POs |

### PII (opt-in via `--incl-pii` or `--pii-only`) — contains customer/order PII

| # | Job | Dispatch args | Runtime |
|---|-----|--------------|---------|
| 10 | `SyncShopwiredCustomersJob` | `null, null` (full) | ~2.5h |
| 11 | `SyncShopwiredOrdersJob` | `5` (quick, ~500 orders) | ~10min |
| 12 | `SyncLinnworksOrdersJob` | `OrderSyncTier::Monthly` | ~1h |

### Flag behaviour

| Flags | Core | PII |
|-------|------|-----|
| _(none)_ | Yes | No |
| `--incl-pii` | Yes | Yes |
| `--pii-only` | No | Yes |
| `--dry-run` | Dry-run | No |
| `--incl-pii --dry-run` | Dry-run | Dry-run |
| `--pii-only --dry-run` | No | Dry-run |
| `--incl-pii --pii-only` | Error — mutually exclusive |

## Implementation Approach

- Data-driven: define each tier as an array of `[class-string|Closure, label, estimate]`
- Single `dispatchGroup()` method loops the array, dispatching or printing dry-run output
- No confirmation prompt (local-only + ShouldBeUnique = safe to re-run)
- Remind user to ensure queue worker is running

## Output

```
Seed Local Database — Dispatching sync jobs to queue

 Core reference data:
   ✓ SyncShopwiredBrandsJob dispatched
   ✓ SyncShopwiredCategoriesJob dispatched
   ...

 9 core jobs dispatched. Ensure your queue worker is running (php artisan horizon).
```

With `--incl-pii`, appends PII tier output + combined count.
With `--dry-run`, shows "would dispatch" and skips actual dispatch.

## Key Files

- **Create:** `app/Presentation/Console/Commands/Dev/SeedLocalDatabaseCommand.php`
- **Reference:** `app/Presentation/Console/Commands/Dev/TestSlackNotificationCommand.php` (Dev command pattern)
- **Import:** `App\Application\Linnworks\Enums\OrderSyncTier` (for Monthly tier)
- **Jobs (core):** `Shopwired/SyncShopwired{Brands,Categories,CustomFields,FilterGroups,Products}Job`, `Linnworks/Sync{LinnworksStockItems,LinnworksSuppliers,FastPurchaseOrders}Job`, `ReviewsIo/SyncProductRatingsJob`
- **Jobs (PII):** `Shopwired/SyncShopwiredCustomersJob`, `Shopwired/SyncShopwiredOrdersJob` (dispatch with `5` for quick mode), `Linnworks/SyncLinnworksOrdersJob`

## Verification

1. `make lint` — Pint + PHPStan + PHPArkitect + Deptrac pass
2. `php artisan dev:seed-sync --dry-run` — lists all core jobs without dispatching
3. `php artisan dev:seed-sync --incl-pii --dry-run` — lists all 12 jobs
4. `php artisan dev:seed-sync --pii-only --dry-run` — lists only 3 PII jobs
5. `php artisan dev:seed-sync` — dispatches core jobs (verify in Horizon dashboard or queue worker output)
6. `APP_ENV=production php artisan dev:seed-sync` — should refuse with error
