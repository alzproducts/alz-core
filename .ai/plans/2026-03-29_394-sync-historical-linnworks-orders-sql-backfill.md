# Plan: Historical Linnworks Order Backfill

## Context

The existing Linnworks order sync (`SyncLinnworksOrdersUseCase`) works perfectly but is constrained by the v2 GetOrders API's ~30-day lookback limit on `fromDate`. We need to sync all ~110,000 historical orders. The workaround: use the Dashboards SQL API to get all order IDs (no date limit), then fetch full orders via the v2 endpoint's `id` parameter (which overrides all other filters, including the date limit).

A separate date-range command enables targeted re-syncs and local testing without risking a full 110k-order backfill.

## Architecture Overview

```
Two commands, one shared UseCase:

BackfillAllLinnworksOrdersCommand ──┐
  (no params, prominent warnings)   │
                                    ├──→ BackfillLinnworksOrdersUseCase(?from, ?to)
BackfillLinnworksOrdersCommand ─────┘      → OrderDashboardsClient::getProcessedOrderIds()  [SQL API]
  (--from, --to required)                  → OrderClient::iterateProcessedOrdersByIds()      [REST API]
                                           → orderRepository->saveMany()                     [DB save]
```

## API Limits

- **entriesPerPage = 200** per response (CONFIRMED via date-based queries)
- **IDS_PER_CHUNK = 200** — Use 200 to match the existing page size. Note: the `id` param is a GET query string array (`id[0]=xxx&id[1]=yyy`), so 200 UUIDs creates a ~9000 char URL. This may hit server-side URL length limits — if so, reduce the constant. Linnworks likely runs IIS (default 16KB limit) so 9KB should be fine, but monitor for 414 errors on first run.

---

## Implementation Steps

### Step 0: Add HTTP-level retry to LinnworksHttpTransport (Infrastructure)

**Gap found during review:** The Linnworks transport does NOT have `->retry()` on HTTP requests (unlike ShopWired's `RetryStrategy` pattern). It only retries 401s (auth refresh). For 429/5xx, it immediately throws — relying on Laravel's job retry system. This is unsafe for command-based usage (backfill).

**Modify:** `app/Infrastructure/Linnworks/LinnworksHttpTransport.php`
- Add `->retry()` to `createBaseRequest()` (or equivalent) for transient failures (429, 5xx, connection errors)
- Follow ShopWired's `RetryStrategy` pattern: exponential backoff, respect `Retry-After` header
- Use `ApiRetryStrategy::defaultRetry()` (`app/Infrastructure/Support/ApiRetryStrategy.php`) as the `when` callback — it already correctly skips 401 (so it won't conflict with the existing `executeWithAuthRetry()` 401 handler)
- This benefits ALL Linnworks API calls, not just the backfill
- Note: existing job-based syncs will now retry transient failures at the HTTP level before the exception reaches `HandleApiExceptions`. This is beneficial (429s resolve silently) but changes failure timing slightly.

**Reference:** `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php` lines ~326-347, `app/Infrastructure/Shopwired/RetryStrategy.php`, and `app/Infrastructure/Support/ApiRetryStrategy.php`

**Tests:** Update existing Linnworks transport tests to verify retry behavior on 429/5xx.

### Step 1: ProcessedOrderIdsQuery (Infrastructure)

**New file:** `app/Infrastructure/Linnworks/Queries/ProcessedOrderIdsQuery.php`

Co-located Row DTO + Query (per Queries/CLAUDE.md pattern from `StockItemBySkuQuery`).

```php
// Row DTO (internal)
final class ProcessedOrderIdsRow extends Data {
    public function __construct(
        #[MapInputName('pkOrderID')]
        public readonly string $orderId,
    ) {}
}

// Query
final readonly class ProcessedOrderIdsQuery extends AbstractLinnworksQuery {
    public function __construct(
        private ?DateTimeImmutable $from = null,
        private ?DateTimeImmutable $to = null,
    ) {}

    protected function buildQueryBody(): string {
        // Base: SELECT pkOrderID FROM [Order] WHERE bProcessed = 'TRUE'
        // Optional: AND dReceievedDate >= '{from}' AND dReceievedDate < '{to}'
        // ORDER BY dReceievedDate ASC
        // Note: "dReceievedDate" typo is Linnworks' actual column name
    }

    public function mapResponse(SqlQueryResponse $response): array {
        // Returns list<Guid>
    }
}
```

**Key files to reference:**
- `app/Infrastructure/Linnworks/Queries/StockItemBySkuQuery.php` (template)
- `app/Infrastructure/Linnworks/Support/SqlQueryBuilder.php` (escaping)

### Step 2: OrderDashboardsClient (Infrastructure + Application Contract)

**New file:** `app/Application/Contracts/Linnworks/OrderDashboardsClientInterface.php`
```php
interface OrderDashboardsClientInterface {
    /** @return list<Guid> */
    public function getProcessedOrderIds(
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): array;
}
```

**New file:** `app/Infrastructure/Linnworks/Clients/OrderDashboardsClient.php`
- Follows `StockDashboardsClient` pattern exactly
- Delegates to `DashboardsClient::execute(new ProcessedOrderIdsQuery($from, $to))`

**Modify:** `app/Infrastructure/Linnworks/LinnworksClientFactory.php`
- Add `createOrderDashboardsClient(): OrderDashboardsClient`

**Modify:** `app/Providers/LinnworksServiceProvider.php`
- Add singleton binding for `OrderDashboardsClientInterface`
- Add to `provides()` array

### Step 3: Add iterateProcessedOrdersByIds to OrderClient

**Modify:** `app/Application/Contracts/Linnworks/OrderClientInterface.php`
```php
/**
 * Iterate processed orders by specific IDs in chunked batches.
 *
 * IDs are chunked internally. Yields batches of orders per chunk.
 * Used for historical backfill where fromDate-based pagination
 * cannot reach beyond the ~30-day API limit.
 *
 * @param list<Guid> $orderIds
 * @return Generator<int, list<LinnworksOrder>, mixed, void>
 */
public function iterateProcessedOrdersByIds(array $orderIds): Generator;
```

**Modify:** `app/Infrastructure/Linnworks/Clients/OrderClient.php`
- Add constant: `private const int IDS_PER_CHUNK = 200;` (may need reducing if URL length causes 414 errors — see API Limits section)
- Add `iterateProcessedOrdersByIds()`: chunks `$orderIds` via `array_chunk()`, calls `fetchPageWithIds()` per chunk, yields batches
- Add private `fetchPageWithIds(array $ids): GetOrdersApiResponse` — similar to `fetchPageWithId()` but with key differences:
  - `'id' => array_map(static fn(Guid $g): string => $g->value, $ids)`
  - Must include `'entriesPerPage' => self::IDS_PER_CHUNK` (without this, the endpoint may default to a lower page size and silently drop orders)
  - Should assert/log if `nextSearchToken` is present in response (would indicate not all orders returned — needs pagination or chunk size reduction)

### Step 4: BackfillLinnworksOrdersUseCase (Application)

**New file:** `app/Application/Linnworks/UseCases/BackfillLinnworksOrdersUseCase.php`

```php
final readonly class BackfillLinnworksOrdersUseCase {
    private const int CHUNKS_PER_BATCH = 5;
    private const int PROGRESS_LOG_INTERVAL = 5;

    public function __construct(
        private OrderDashboardsClientInterface $orderDashboardsClient,
        private OrderClientInterface $orderClient,
        private LinnworksOrderRepositoryInterface $orderRepository,
        private LoggerInterface $logger,
    ) {}

    public function execute(
        ?DateTimeImmutable $from = null,
        ?DateTimeImmutable $to = null,
    ): SyncResult {
        // 0. Assert both-or-neither for from/to (prevents ambiguous single-sided ranges)
        // 1. Get order IDs via SQL ($this->orderDashboardsClient->getProcessedOrderIds($from, $to))
        // 2. Log total count
        // 3. Iterate by ID chunks via Generator ($this->orderClient->iterateProcessedOrdersByIds($orderIds))
        // 4. Buffer chunks, flush every CHUNKS_PER_BATCH via $this->orderRepository->saveMany()
        // 5. Return SyncResult (no latestLastUpdated — not needed for backfill)
    }
}
```

**Pattern:** Same buffer/flush as `SyncLinnworksOrdersUseCase` (`app/Application/Linnworks/UseCases/SyncLinnworksOrdersUseCase.php`) but:
- No cursor tracking (no `latestLastUpdated`)
- Knows total count upfront (for progress logging: "Backfilled 5000/110000")
- Different log messages ("backfill" not "sync")

### Step 5: Two Commands (Presentation)

#### 5a: Date-range command (safe, everyday use)

**New file:** `app/Presentation/Console/Commands/BackfillLinnworksOrdersCommand.php`

```
linnworks:backfill-orders
    {--from= : Start date (Y-m-d) — REQUIRED}
    {--to= : End date (Y-m-d) — REQUIRED}
    {--dry-run : Show order count without syncing}
```

**Dependencies:** Injects both `BackfillLinnworksOrdersUseCase` and `OrderDashboardsClientInterface` (client used for --dry-run count).

**Behaviour:**
- Both `--from` and `--to` required — validates and errors if missing
- `--dry-run`: Calls `OrderDashboardsClientInterface::getProcessedOrderIds($from, $to)` directly, shows count, exits
- Shows date range and count, then syncs via UseCase
- Catches domain exceptions for user-friendly output + exit codes

#### 5b: Full backfill command (dangerous, use with care)

**New file:** `app/Presentation/Console/Commands/BackfillAllLinnworksOrdersCommand.php`

```
linnworks:backfill-all-orders
    {--dry-run : Show total order count without syncing}
    {--force : Skip confirmation prompt (for scripts/automation)}
```

**Dependencies:** Same as 5a — injects both UseCase and `OrderDashboardsClientInterface`.

**Behaviour:**
- Prominent warning in description: "CAUTION: Syncs ALL historical orders (~110,000+). This is a long-running operation."
- Shows total count from SQL query (via client)
- Requires explicit confirmation unless `--force`: "This will sync {count} orders. This may take several hours. Type 'yes' to continue:"
- `--dry-run`: Shows count only, exits
- Same error handling as date-range command

**Note:** Uses `--force` (not `--no-interaction`) because Laravel's built-in `--no-interaction` makes `confirm()` return false (abort). `--force` is an explicit opt-in to proceed without asking.

**Pattern reference:** `BackfillShopwiredOrdersCommand` (`app/Presentation/Console/Commands/BackfillShopwiredOrdersCommand.php`)

### Step 6: Tests

- **Unit:** `ProcessedOrderIdsQuery` — SQL generation with/without dates, escaping, response mapping to `list<Guid>`
- **Unit:** `BackfillLinnworksOrdersUseCase` — mock client+repo, verify orchestration flow, verify SyncResult
- **Integration:** `OrderDashboardsClient` — HTTP mock, verify SQL sent to endpoint
- **Integration:** `OrderClient::iterateProcessedOrdersByIds` — HTTP mock, verify chunking, verify `id` array format in request
- **Feature:** `BackfillLinnworksOrdersCommand` — mock UseCase, verify option validation + output
- **Feature:** `BackfillAllLinnworksOrdersCommand` — mock UseCase, verify confirmation prompt + warning output

---

## Files Summary

| Action | File | Layer |
|--------|------|-------|
| **New** | `Infrastructure/Linnworks/Queries/ProcessedOrderIdsQuery.php` | Infrastructure |
| **New** | `Application/Contracts/Linnworks/OrderDashboardsClientInterface.php` | Application |
| **New** | `Infrastructure/Linnworks/Clients/OrderDashboardsClient.php` | Infrastructure |
| **New** | `Application/Linnworks/UseCases/BackfillLinnworksOrdersUseCase.php` | Application |
| **New** | `Presentation/Console/Commands/BackfillLinnworksOrdersCommand.php` | Presentation |
| **New** | `Presentation/Console/Commands/BackfillAllLinnworksOrdersCommand.php` | Presentation |
| **Modify** | `Infrastructure/Linnworks/LinnworksHttpTransport.php` | Infrastructure |
| **Modify** | `Application/Contracts/Linnworks/OrderClientInterface.php` | Application |
| **Modify** | `Infrastructure/Linnworks/Clients/OrderClient.php` | Infrastructure |
| **Modify** | `Infrastructure/Linnworks/LinnworksClientFactory.php` | Infrastructure |
| **Modify** | `Providers/LinnworksServiceProvider.php` | Providers |

---

## Design Decisions

1. **Two commands, not one.** Full backfill (~110k orders, hours of runtime, significant API cost) is a fundamentally different operation from date-range sync. Separating them prevents accidental full backfills in production. The full backfill command has prominent warnings and explicit confirmation.

2. **No SQL pagination.** ~110k rows x ~36 bytes (UUID only) = ~4MB. Trivially small. Linnworks handles this fine per user confirmation. Can add pagination later if needed.

3. **Separate UseCase from existing sync.** `BackfillLinnworksOrdersUseCase` is distinct from `SyncLinnworksOrdersUseCase` — different data source (SQL + ID-based REST vs date-based REST), different concerns (no cursor), different entry point (command vs job). Duplicating ~30 lines of buffer/flush is preferable to forced abstraction.

4. **OrderDashboardsClient as separate facade.** Follows established pattern (`StockDashboardsClient`). Keeps SQL queries separate from REST API calls. Clean interface for Application layer.

5. **Generator for memory efficiency.** 110k orders with full items/properties/notes would be enormous in memory. Generator + chunking keeps memory bounded. Peak memory: ~`CHUNKS_PER_BATCH * IDS_PER_CHUNK` orders.

6. **Command-only, not scheduled.** This is a manual operation (potentially hours to complete). User controls when to run. No job/queue needed.

7. **Shared UseCase, split at Presentation.** Both commands call the same `BackfillLinnworksOrdersUseCase::execute(?from, ?to)`. The safety split is at the command level (UX/confirmation), not the business logic level.

---

## Verification

1. `make lint` — all new code passes PHPStan, Pint, PHPArkitect, Deptrac
2. `make test` — all existing + new tests pass
3. Dry run date range: `php artisan linnworks:backfill-orders --from=2026-03-01 --to=2026-03-29 --dry-run`
4. Actual date range sync: `php artisan linnworks:backfill-orders --from=2026-03-28 --to=2026-03-29`
5. Verify orders appear in database with items, extended properties, and notes
6. Dry run full: `php artisan linnworks:backfill-all-orders --dry-run`
