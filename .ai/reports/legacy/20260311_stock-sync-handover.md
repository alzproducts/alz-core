# Stock Sync Feature Handover

## 1. Feature Overview

The Stock Sync feature is a cron-based process that synchronises available stock levels from Linnworks (source of truth) to ShopWired (e-commerce frontend). It runs periodically, fetches all stock levels from both systems, compares them by SKU, and updates ShopWired for any SKUs where the stock level has drifted out of sync. It uses an event-driven approach — each stock difference triggers a `StockAvailableUpdatedEvent`, which a listener handles to POST the update to ShopWired.

## 2. Architecture Diagram

```
html/cron/minutes/stock_sync.php          (Cron entry point)
    │
    ▼
SyncStockController::actionCron()          (Controller)
    │
    ▼
SyncStockLevelService::runSync()           (Orchestrator service)
    │
    ├── GetStockLevelsToUpdateService::getLevels()
    │       │
    │       ├── QueryAllStockLevels → Linnworks API
    │       │     SQL: SELECT FROM [View_FullStockLevels]
    │       │     Returns: LwC collection → [sku => stock]
    │       │
    │       ├── ShopWiredA::productsGetAllAsCollect('id,sku,variations,stock')
    │       │     API: GET /products (paginated, 100 per page)
    │       │     Returns: SwProductCollection → getAllStock() → [sku => stock]
    │       │
    │       └── Compare: intersectByKeys → diffAssoc
    │             Returns: AlzC of [sku => level] (Linnworks values, mismatches only)
    │
    └── For each mismatch:
            dispatch StockAvailableUpdatedEvent(sku, level)
                │
                ▼
            StockAvailableUpdatedListener::__invoke()
                │
                ▼
            ShopWiredA::post('stock', {sku, quantity})
```

## 3. External Integrations

### Linnworks — Stock Level Query

- **Endpoint**: `Dashboards/ExecuteCustomScriptQuery` (Linnworks API)
- **Method**: GET with SQL query parameter
- **SQL Query** (`Linnworks\Sql\StockLevel::allStockLevels()`):
  ```sql
  SELECT
      ItemNumber AS 'sku',
      (CASE
          WHEN Level_LessOrderBook < 0 THEN 0
          ELSE Level_LessOrderBook
      END) AS 'stock'
  FROM [View_FullStockLevels]
  WHERE pkStockLocationId = '00000000-0000-0000-0000-000000000000'
  ```
- **Key details**:
  - Uses `View_FullStockLevels` (a Linnworks system view)
  - `Level_LessOrderBook` = physical stock minus items reserved for open orders
  - Negative stock is floored to `0`
  - Only queries the **default location** (`00000000-0000-0000-0000-000000000000`)
  - Returns all SKUs (no limit applied on the SQL side)

### ShopWired — Read Stock Levels

- **Endpoint**: `GET /products`
- **Fields requested**: `id,sku,variations,stock`
- **Embeds**: All embeddable product fields (see Known Issues #1)
- **Pagination**: Fetches all active products in batches of 100, using `productCount()` to determine total
- **Processing**: `productsGetAllAsCollect()` → `getAllStock()` which:
  1. Flattens variations into the main collection (`flattenVariations()`)
  2. Keys by `sku`, extracts just the `stock` field → `[sku => stock_level]`

### ShopWired — Update Stock Level

- **Endpoint**: `POST /stock`
- **Payload**: `{ "sku": "<sku>", "quantity": <level> }`
- **Called once per SKU** that has a mismatch

## 4. Code Inventory

| File | Purpose |
|------|---------|
| `html/cron/minutes/stock_sync.php` | Cron entry point — bootstraps container and runs controller |
| `legacy/src/Mvc/Controller/Cron/Stock/SyncStockController.php` | Cron controller, delegates to `SyncStockLevelService` |
| `legacy/src/Mvc/Controller/Cron/CronController.php` | Abstract cron base class with disable/debug flags |
| `legacy/src/Mvc/Service/Inventory/Stock/SyncStockLevelService.php` | Main orchestrator: gets differences, dispatches events |
| `legacy/src/Mvc/Service/Inventory/Stock/GetStockLevelsToUpdateService.php` | Compares Linnworks vs ShopWired stock, returns mismatches |
| `legacy/src/Mvc/Model/QueryResult/Linnworks/OneQuery/Stock/QueryAllStockLevels.php` | Linnworks SQL query wrapper for stock levels |
| `legacy/src/Linnworks/Sql/StockLevel.php` | Raw SQL strings for Linnworks stock queries |
| `legacy/src/Mvc/Model/QueryResult/Linnworks/QueryApiController.php` | Abstract base for Linnworks SQL-over-API queries |
| `legacy/src/Api/Linn2/src/Endpoint/Query.php` | Linnworks API client — `ExecuteCustomScriptQuery` endpoint |
| `legacy/src/Api/Linn2/src/Model/QueryResult.php` | Hydrates Linnworks API response; defaults to empty on failure |
| `legacy/src/AlzMvc/Events/Product/StockAvailableUpdatedEvent.php` | Event DTO carrying `sku` + `stockLevel` |
| `legacy/src/AlzMvc/Listeners/Product/Stock/StockAvailableUpdatedListener.php` | Listener that POSTs stock update to ShopWired |
| `legacy/src/AlzMvc/Core/Container/Event/listeners.php` | Event→Listener registration (DI container config) |
| `legacy/src/Mvc/Collection/SwProductCollection.php` | ShopWired product collection with `getAllStock()` macro |
| `legacy/src/Mvc/Collection/AlzC.php` | Base collection with `flattenOneKey()`, `keyBy()` macros |
| `legacy/src/ShopWired/src/ShopWiredA.php` | ShopWired API client — product fetching + stock posting |
| `legacy/src/ShopWired/src/ShopApiDefaults.php` | Defines `getEmbeddableProducts()` list |

## 5. Data Structures

### Linnworks Stock (after collection processing)

Raw response: array of `{sku, stock}` rows from Linnworks API.

Processing chain:
```
asCollectArr()          → LwC [{sku: 'SKU-001', stock: 15}, ...]
keyBy('sku')            → LwC keyed by sku
flattenOneKey('stock', 'sku')  → LwC [sku => stock_level]
intersectByKeys($swStock)      → (filtered to only SKUs in ShopWired)
```

Result: `AlzC { "SKU-001" => 15, "SKU-002" => 0, ... }`

### ShopWired Stock (after collection processing)

Raw response: paginated product objects including `variations` sub-objects.

Processing chain:
```
productsGetAllAsCollect('id,sku,variations,stock')
→ getAllStock()
→ flattenVariations()           (extracts variation SKUs alongside parent SKUs)
→ flattenOneKey('stock', 'sku') (keys by sku, extracts stock value)
```

Result: `SwProductCollection { "SKU-001" => 15, "SKU-002" => 3, ... }`

Note: `flattenOneKey` internally calls `keyBy('sku')` — if duplicate SKUs exist, only the last one is retained silently.

### Comparison Result

```php
$lwStock->diffAssoc($swStock)
// Returns Linnworks entries whose [sku => level] pair is not in ShopWired
// i.e. SKUs where levels differ → { "SKU-002" => 0 }
```

### StockAvailableUpdatedEvent

| Property | Type | Description |
|----------|------|-------------|
| `sku` | `string` | Product SKU |
| `stockLevel` | `int` | Linnworks available stock level |
| Event name constant | `string` | `product.stock_available_updated.event` |

## 6. Business Rules

1. **Source of truth**: Linnworks stock levels are authoritative; ShopWired is updated to match
2. **Available stock calculation**: `Level_LessOrderBook` from Linnworks — physical stock minus open order reservations
3. **Floor at zero**: Negative stock (more reserved than physical) is reported and pushed as `0`
4. **Default location only**: Only stock at location `00000000-0000-0000-0000-000000000000` is considered
5. **SKU intersection**: Only SKUs present in **both** Linnworks and ShopWired are compared — SKUs unique to either system are ignored
6. **Variation handling**: ShopWired parent products with variations are flattened so each variation's stock is compared individually by its SKU
7. **Diff-only updates**: Only SKUs where the stock level actually differs between systems trigger an update
8. **One-by-one updates**: Each mismatched SKU is updated individually via a separate POST to ShopWired (no batching)

## 7. Configuration

| Config | Source | Description |
|--------|--------|-------------|
| Cron schedule | Server crontab | Entry point is `html/cron/minutes/stock_sync.php` (directory name implies per-minute or sub-minute frequency) |
| `is-lw-query-cache` | DI container | Controls Linnworks query caching — **has no effect on this feature** (see Known Issues #3) |
| `lw-query-global-ttl` | DI container | Cache TTL for Linnworks queries — also bypassed by this feature |
| Linnworks API auth | DI container / env | Configured via `Linn2\Http\RestClient` |
| ShopWired API auth | DI container / env | Configured via `ShopWiredA` client |

## 8. Known Issues & Technical Debt

### Performance

1. **Excessive ShopWired embed payload**: `productsGetAllAsCollect()` passes ALL embeddable resources as the embed parameter (`images`, `brand`, `categories`, `related`, `extras`, `customization_fields`, `ebay_shipping_rates`, `options`, `vat_relief`, `digital_files`, `variations`, `choices`, etc.). Only `variations` and `stock` are actually needed. This substantially inflates response size and API processing time.

2. **Full dataset fetch every run**: Both systems return their full product/stock catalogue on every cron execution. No delta/incremental sync mechanism exists — every run is a complete comparison.

3. **No batching on ShopWired updates**: Each stock mismatch results in a separate HTTP POST. For catalogues with many mismatches, this is slow and may hit rate limits.

### Caching

4. **Caching bypassed for Linnworks query**: `QueryApiController::asCollectArr()` calls `queryResult()` directly, which goes to `getQueryResultLive()`. The cache retrieval block inside `queryResult()` is commented out. The `query()` method (which does use cache) is not used here. Every cron run hits the Linnworks API live.

### Resilience

5. **Silent failure on Linnworks API error**: In `Query::execute()`, exceptions from the API call are caught, logged via `Alert::dangerWithLog()`, and execution continues. `QueryResult` is constructed with default values (`IsError=true`, `Results=[]`). `hydrate(null)` is a no-op, so `asCollectArr()` returns an **empty collection**. The sync then logs "No incorrect stock levels to update" and exits — the failure is not distinguishable from a genuine no-change run without checking error logs.

6. **No retry logic for ShopWired updates**: If a `POST /stock` call fails, the exception is caught, an error is logged, and that SKU is skipped. It will only be retried on the next cron run if the levels still differ.

7. **No transaction/rollback**: Partial updates are possible — if the process fails mid-loop, some SKUs will have been updated and others won't. The next run will catch and correct the remainder.

### Correctness

8. **`setDisableCron(false)` is a no-op**: The entry point calls `$syncStockController->setDisableCron(false)->actionCron()`, but `SyncStockController::actionCron()` never calls `isSystemRunAndDisabled()`. The inherited disable-check mechanism from `CronController` exists but is not wired up for this controller.

9. **Duplicate SKU handling**: `keyBy('sku')` silently retains only the last item when duplicate SKUs exist. If `View_FullStockLevels` returns multiple rows for the same SKU, or if ShopWired has a parent and variant sharing the same SKU, only the last encountered value is used. No warning is raised.

### Architecture

10. **Per-SKU synchronous event dispatch**: While the event-driven design is clean for decoupling, dispatching potentially hundreds of events in a loop with synchronous listeners is functionally equivalent to direct method calls with added overhead. Useful if additional listeners are registered later, but currently adds indirection without benefit.