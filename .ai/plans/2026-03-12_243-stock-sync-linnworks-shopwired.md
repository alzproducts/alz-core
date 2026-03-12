# Stock Sync: Linnworks → ShopWired

## Context

The legacy stock sync (cron-based, in `examples/old-server/`) fetches ALL stock from both Linnworks and ShopWired APIs every run, compares, and pushes individual updates one-by-one. This is slow, wasteful, and has no incremental sync.

**Goal**: Replace with a modern clean architecture implementation using two sync modes:
- **Delta sync** (every 5 min) — query only recently-changed stock from Linnworks via `StockLevel.LastUpdateDate`, compare against local ShopWired DB, push differences in batches of 15
- **Full sync** (every 15 min) — query all stock from `View_FullStockLevels`, compare against local DB, push differences. Catches edge cases delta misses (e.g., order lock/unlock changing available stock without updating `StockLevel.LastUpdateDate`)

**Key design decisions** (confirmed with user):
- Local ShopWired DB for comparison (not API)
- Update local DB stock after successful push (avoid redundant pushes)
- `Application/Inventory/` namespace for use cases (cross-cutting concern)
- Minimal delta columns: `sku`, `level`, `lastUpdateDate`
- Simplified SQL (inline ISO date, no DECLARE blocks)
- Blocking cache lock in use cases so full + delta run serially when they overlap (prevents stock ping-pong from different data sources)

## Existing Infrastructure to Reuse

| Component | Path | What it does |
|-----------|------|-------------|
| `StockClientInterface` | `app/Application/Contracts/Shopwired/StockClientInterface.php` | Bulk push to ShopWired, batches of 15, concurrent |
| `StockClient` | `app/Infrastructure/Shopwired/Clients/StockClient.php` | Implementation with pool POST |
| `ItemStockLevel` | `app/Domain/Inventory/ValueObjects/ItemStockLevel.php` | Value object (sku + quantity) |
| `AbstractLinnworksQuery` | `app/Infrastructure/Linnworks/Queries/AbstractLinnworksQuery.php` | Template Method with READ UNCOMMITTED |
| `DashboardsClient` | `app/Infrastructure/Linnworks/Clients/DashboardsClient.php` | Executes query objects via SQL-over-API |
| `SqlQueryBuilder` | `app/Infrastructure/Linnworks/Support/SqlQueryBuilder.php` | SQL escaping, IN clauses |
| `ProductModel` | `app/Infrastructure/Shopwired/Models/ProductModel.php` | Local DB: `shopwired.products` (has `stock` column) |
| `ProductVariationModel` | `app/Infrastructure/Shopwired/Models/ProductVariationModel.php` | Local DB: `shopwired.product_variations` (has `stock` column) |
| `DatabaseGateway` | `app/Infrastructure/Database/DatabaseGateway.php` | DB access with exception translation |
| `LockableCacheInterface` | `app/Application/Contracts/LockableCacheInterface.php` | Cache lock contract for mutual exclusion |
| `InventoryServiceProvider` | `app/Providers/InventoryServiceProvider.php` | Existing provider for inventory bindings |
| `QueueName` enum | (existing) | Queue routing enum used by all jobs |

## Implementation Steps

### Step 1: Migration — `sync_cursors` table

Create `database/migrations/YYYY_MM_DD_HHMMSS_create_sync_cursors_table.php`

```
sync_cursors
├── id (uuid, PK)
├── sync_type (string, unique) — e.g. 'linnworks_stock_delta'
├── cursor_value (string) — ISO datetime of last successful sync
├── created_at (timestamp)
└── updated_at (timestamp)
```

Simple key-value table, reusable for future sync cursors. No schema prefix (lives in `public` schema — it's app-internal, not ShopWired-specific).

### Step 2: Domain — Sync cursor value object (optional, very light)

Skip a dedicated value object — the cursor is just a datetime string. The repository interface will accept/return `CarbonImmutable|null`.

### Step 3: Application Contracts

**`app/Application/Contracts/Inventory/StockLevelQueryClientInterface.php`**
```php
interface StockLevelQueryClientInterface
{
    /** @return list<ItemStockLevel> All stock levels from View_FullStockLevels */
    public function getAllStockLevels(): array;

    /** @return list<StockLevelDelta> Changed stock since given date */
    public function getStockLevelsSince(CarbonImmutable $since): array;
}
```

**`app/Application/Contracts/Inventory/SyncCursorRepositoryInterface.php`**
```php
interface SyncCursorRepositoryInterface
{
    public function getLastSyncDate(string $syncType): ?CarbonImmutable;
    public function updateLastSyncDate(string $syncType, CarbonImmutable $date): void;
}
```

**`app/Application/Contracts/Inventory/ShopwiredLocalStockRepositoryInterface.php`**
```php
interface ShopwiredLocalStockRepositoryInterface
{
    /** @return array<string, int> sku => stock_level (products + variations, keyed by SKU) */
    public function getAllStockLevelsBySku(): array;

    /** @return array<string, int> sku => stock_level (filtered to given SKUs only) */
    public function getStockLevelsBySkus(array $skus): array;

    /** Update local stock after successful push to ShopWired */
    public function updateStockLevels(array $items): void;
}
```

### Step 4: Linnworks Query Objects

**`app/Infrastructure/Linnworks/Queries/FullStockLevelQuery.php`**
- Row DTO: `FullStockLevelRow` (sku, stock)
- SQL: `SELECT ItemNumber AS 'sku', CASE WHEN Level_LessOrderBook < 0 THEN 0 ELSE Level_LessOrderBook END AS 'stock' FROM [View_FullStockLevels] WHERE pkStockLocationId = '00000000-0000-0000-0000-000000000000'`
- Returns: `list<ItemStockLevel>` (reuse existing value object)

**`app/Infrastructure/Linnworks/Queries/DeltaStockLevelQuery.php`**
- Row DTO: `DeltaStockLevelRow` (sku, level, lastUpdateDate)
- Constructor: `__construct(CarbonImmutable $since)`
- SQL (simplified, no DECLARE):
  ```sql
  SELECT
      si.ItemNumber AS 'sku',
      CASE WHEN (sl.Quantity - sl.InOrderBook) < 0 THEN 0 ELSE (sl.Quantity - sl.InOrderBook) END AS 'level',
      CAST(sl.LastUpdateDate AS DATETIME) AS 'lastUpdateDate'
  FROM StockLevel AS sl
  INNER JOIN [StockItem] AS si ON si.pkStockItemId = sl.fkStockItemId
  WHERE sl.fkStockLocationId = '00000000-0000-0000-0000-000000000000'
    AND CAST(sl.LastUpdateDate AS DATETIME) > '{$escapedDate}'
    AND si.IsArchived = 'False'
  ORDER BY sl.LastUpdateDate ASC
  ```
- Returns: `StockLevelDelta` objects (new Application DTO with sku, level, lastUpdateDate)

### Step 5: Application DTO

**`app/Application/Inventory/DTOs/StockLevelDelta.php`**
```php
final readonly class StockLevelDelta
{
    public function __construct(
        public string $sku,
        public int $level,
        public CarbonImmutable $lastUpdateDate,
    ) {}
}
```

### Step 6: Infrastructure — Linnworks Client Facade

**`app/Infrastructure/Linnworks/Clients/StockLevelDashboardsClient.php`**
- Implements `StockLevelQueryClientInterface`
- `getAllStockLevels()` → executes `FullStockLevelQuery`
- `getStockLevelsSince(CarbonImmutable $since)` → executes `DeltaStockLevelQuery`

### Step 7: Infrastructure — Repositories

**`app/Infrastructure/Database/Repositories/EloquentSyncCursorRepository.php`**
- Implements `SyncCursorRepositoryInterface`
- Companion model: `app/Infrastructure/Database/Models/SyncCursorModel.php`
- Uses `DatabaseGateway` for exception translation
- `getLastSyncDate()` returns null on first run (triggers full-range delta)
- `updateLastSyncDate()` uses `updateOrCreate` keyed by `sync_type`

**`app/Infrastructure/Shopwired/Repositories/EloquentShopwiredLocalStockRepository.php`**
- Implements `ShopwiredLocalStockRepositoryInterface`
- Uses `DatabaseGateway` for exception translation
- `getAllStockLevelsBySku()`: UNION query across `shopwired.products` (WHERE sku IS NOT NULL AND stock IS NOT NULL) and `shopwired.product_variations` (WHERE sku IS NOT NULL)
- `getStockLevelsBySkus(array $skus)`: Same but filtered by SKU list
- `updateStockLevels(list<ItemStockLevel>)`: Update both tables by SKU match

### Step 8: Application — Use Cases

Both use cases acquire a **blocking cache lock** `Cache::lock('stock-sync-to-shopwired', 180)->block(120)` before doing any work. This ensures full + delta run serially when they overlap (wait up to 2 min for lock, lock auto-expires after 3 min safety). Both inject `LockableCacheInterface` (existing contract) for testability.

**`app/Application/Inventory/UseCases/SyncFullStockToShopwiredUseCase.php`**
```
1. Acquire blocking lock 'stock-sync-to-shopwired' (block up to 120s)
2. Fetch all stock from Linnworks (FullStockLevelQuery)
3. Fetch all local ShopWired stock (ShopwiredLocalStockRepository)
4. Compare: find SKUs where levels differ
5. Convert differences to list<ItemStockLevel>
6. Push to ShopWired (StockClientInterface) — already batches at 15
7. Update local DB stock (ShopwiredLocalStockRepository)
8. Log summary (X differences found, Y updated)
9. Lock released (closure scope)
```

**`app/Application/Inventory/UseCases/SyncDeltaStockToShopwiredUseCase.php`**
```
1. Read cursor (SyncCursorRepositoryInterface)
2. If null cursor → use sensible default (e.g., 24 hours ago)
3. Acquire blocking lock 'stock-sync-to-shopwired' (block up to 120s)
4. Fetch delta from Linnworks (DeltaStockLevelQuery with cursor)
5. If empty → log "no changes", release lock, return
6. Extract SKUs from delta results
7. Fetch local ShopWired stock for those SKUs only (efficient)
8. Compare: find SKUs where levels differ
9. Convert differences to list<ItemStockLevel>
10. Push to ShopWired (StockClientInterface)
11. Update local DB stock
12. Update cursor to MAX(lastUpdateDate) from delta results
13. Log summary
14. Lock released (closure scope)
```

### Step 9: Application — Jobs

Follow existing Pattern A job structure: `final class`, `ShouldBeUnique`, `ShouldQueue`, constructor calls `$this->onQueue(QueueName::Default->value)`, `handle()` with constructor-injected use case + `LoggerInterface`, 3-catch exception pattern (`TransientApiFailure`, `PermanentApiFailure`, `Throwable`), `failed()` method with `Log` facade.

**`app/Application/Jobs/Inventory/SyncFullStockToShopwiredJob.php`**
- Dispatches `SyncFullStockToShopwiredUseCase`
- `$timeout = 120`, `$tries = 2`, `$backoff = [60]`, `$uniqueFor = 900` (15 min)
- `uniqueId()` returns `'sync-full-stock-to-shopwired'`

**`app/Application/Jobs/Inventory/SyncDeltaStockToShopwiredJob.php`**
- Dispatches `SyncDeltaStockToShopwiredUseCase`
- `$timeout = 60`, `$tries = 2`, `$backoff = [30]`, `$uniqueFor = 300` (5 min)
- `uniqueId()` returns `'sync-delta-stock-to-shopwired'`

### Step 10: Schedule Registration

Create new `app/Providers/Schedule/InventoryScheduleServiceProvider.php`:
- `SyncDeltaStockToShopwiredJob` → `everyFiveMinutes()->onOneServer()->withoutOverlapping(5)`
- `SyncFullStockToShopwiredJob` → `everyFifteenMinutes()->onOneServer()->withoutOverlapping(15)`
- **Mutual exclusion handled in use cases** via blocking cache lock (not at schedule level). `withoutOverlapping` here just prevents duplicate self-dispatch.
- Register in `bootstrap/providers.php` (non-deferred, alongside other schedule providers)

### Step 11: Service Provider Bindings

Extend **existing** `app/Providers/InventoryServiceProvider.php` with new bindings:
- `StockLevelQueryClientInterface` → `StockLevelDashboardsClient`
- `SyncCursorRepositoryInterface` → `EloquentSyncCursorRepository`
- `ShopwiredLocalStockRepositoryInterface` → `EloquentShopwiredLocalStockRepository`

## File Summary

| New File | Layer | Purpose |
|----------|-------|---------|
| Migration: `create_sync_cursors_table` | DB | Cursor tracking table |
| `SyncCursorModel` | `Infrastructure/Database/Models/` | Eloquent model for sync_cursors |
| `StockLevelQueryClientInterface` | `Application/Contracts/Inventory/` | Linnworks stock query contract |
| `SyncCursorRepositoryInterface` | `Application/Contracts/Inventory/` | Cursor read/write contract |
| `ShopwiredLocalStockRepositoryInterface` | `Application/Contracts/Inventory/` | Local DB stock contract |
| `StockLevelDelta` DTO | `Application/Inventory/DTOs/` | Delta query result DTO |
| `FullStockLevelQuery` + `FullStockLevelRow` | `Infrastructure/Linnworks/Queries/` | Full stock SQL query object |
| `DeltaStockLevelQuery` + `DeltaStockLevelRow` | `Infrastructure/Linnworks/Queries/` | Delta stock SQL query object |
| `StockLevelDashboardsClient` | `Infrastructure/Linnworks/Clients/` | Client facade for stock queries |
| `EloquentSyncCursorRepository` | `Infrastructure/Database/Repositories/` | Cursor persistence (app-internal) |
| `EloquentShopwiredLocalStockRepository` | `Infrastructure/Shopwired/Repositories/` | Local ShopWired stock reads + updates |
| `SyncFullStockToShopwiredUseCase` | `Application/Inventory/UseCases/` | Full sync orchestrator |
| `SyncDeltaStockToShopwiredUseCase` | `Application/Inventory/UseCases/` | Delta sync orchestrator |
| `SyncFullStockToShopwiredJob` | `Application/Jobs/Inventory/` | Scheduled full sync job |
| `SyncDeltaStockToShopwiredJob` | `Application/Jobs/Inventory/` | Scheduled delta sync job |
| `InventoryScheduleServiceProvider` | `Providers/Schedule/` | Schedule registration (new) |
| Bindings update | `Providers/InventoryServiceProvider` | Extend existing provider (not new) |

## Verification

1. **Unit tests**: Query objects (SQL generation), use cases (mock dependencies), repository (DB queries)
2. **Run `make lint`**: Verify PHPStan, PHPArkitect (layer boundaries, naming), Deptrac (dependency flow)
3. **Manual test**: `php artisan tinker` → instantiate use case via container → execute
4. **Integration test**: Run delta sync with known Linnworks data, verify ShopWired local DB updated
5. **Schedule verification**: `php artisan schedule:list` shows both jobs at correct frequencies
