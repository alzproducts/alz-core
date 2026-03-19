# Linnworks Orders Sync — Implementation Plan

## Context

We need a multi-tier sync system to pull processed orders from the Linnworks v2 GetOrders API into PostgreSQL. Five redundancy tiers (cursor/hourly/daily/weekly/full) ensure eventual consistency despite API write visibility lag. All tiers share a single UseCase and idempotent Postgres upsert logic (`INSERT ON CONFLICT DO UPDATE`).

**Endpoint**: `GET /v2/orders` ([docs](https://apidocs.linnworks.net/reference/getorders-1)) — **tentative**, needs early validation testing.

### Resolved Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Endpoint | v2 GetOrders (`GET /v2/orders`) | Better than SearchProcessedOrders: `LastUpdated` filter, token pagination, `id` lookup |
| Order scope | Processed only | `includeProcessed=true`, persist only `ProcessedOrders` array |
| Line items | Skip for now | Order-level data only; add `linnworks.order_items` when a feature needs it |
| Lookup tables | None | Denormalized strings; future features can add lookup + FK matching on stored string |
| Cursor field | `LastUpdated` | Single field tracking last modification time; simpler than max-of-4-dates |
| Pagination | Token-based | `searchToken`/`NextSearchToken`; inherently stable (no moving-dataset problem) |
| Sync tiers | All 5 | Low cost (same UseCase), high reliability against unknown API lag characteristics |
| Enums | Strings first | Store as VARCHAR; create domain enums in follow-up PR after backsync reveals full value space |
| Pagination | Generator pattern | Client handles token pagination internally; yields batches via Generator (matches `iterateStockItemBatches`) |
| Schema source | Property audit | `.ai/reports/legacy/20260315_Order-property-audit.md` recommendations section |

---

## Architecture Overview

```
┌─ Application/Jobs ─────────────────────────────────────────────────┐
│  SyncLinnworksOrdersByCursorJob        SyncLinnworksOrdersJob      │
│         │                                     │                    │
│         ▼                                     ▼                    │
│  SyncLinnworksCursorUseCase ──delegates──▶ SyncLinnworksOrdersUseCase │
│         │                                  │           │           │
├─────────┼──────────────────────────────────┼───────────┼───────────┤
│ Infra   ▼                                  ▼           ▼           │
│  CursorRepo (existing)           OrderClient (API)   OrderRepo     │
├────────────────────────────────────────────────────────────────────┤
│ Domain  LinnworksOrder (strings for source/vendor/status)           │
└────────────────────────────────────────────────────────────────────┘
```

**Flow:**
- `SyncLinnworksCursorUseCase`: reads cursor → delegates to `SyncLinnworksOrdersUseCase(fromDate)` → advances cursor to `result.latestLastUpdated`
- `SyncLinnworksOrdersUseCase`: fetches orders via token pagination → bulk upserts each page → returns result with max `LastUpdated`
- Both jobs follow Pattern A exception handling (TransientApiFailure / PermanentApiFailure / Throwable)

**Key difference from original diagram**: No `toDate` parameter — the v2 endpoint only has `fromDate` (based on `LastUpdated`). The use case signature becomes `execute(DateTimeImmutable $fromDate)` not `execute($from, $to)`.

---

## Implementation Phases

### Phase 0: Early Validation (FIRST — before building the full feature)

> The v2 GetOrders endpoint is untested. Validate the raw response structure before committing to DTOs and schema.

**Steps** (zero new code needed — use existing transport directly):
```php
// In tinker:
$transport = app(LinnworksTransportInterface::class);
$response = $transport->get('/v2/orders', [
    'fromDate' => now()->subDay()->toIso8601String(),
    'entriesPerPage' => 5,
    'includeProcessed' => 'true',
]);
dd($response->json());
```

**Validate:**
1. Response has `ProcessedOrders` array (not just `OpenOrders`)
2. Each order has `LastUpdated` field (our cursor field)
3. Nested objects exist: `GeneralInfo`, `TotalsInfo`, `ShippingInfo`, `CustomerInfo`
4. Field names match property audit (e.g., `GeneralInfo.ReferenceNum`, `TotalsInfo.TotalCharge`)
5. `NextSearchToken` is returned for pagination
6. Auth works (v2 endpoint uses same session token as v1)

**If response structure diverges**: Adjust DTOs and migration before proceeding with Phase 1+.

### Phase 1: Domain Layer

**1.1 Domain Data Object**
- `app/Domain/Linnworks/ValueObjects/LinnworksOrder.php` — NEW

`final readonly class` with all flattened order fields. Constructor receives typed values (`DateTimeImmutable` for dates, `float` for money, `string` for source/vendor/status and all other text fields).

No enums in this phase. Source, vendor, and status are stored as raw strings. After backsync populates the database with 1-2 years of orders, we'll query distinct values and create domain enums in a follow-up PR:
```sql
SELECT DISTINCT source, sub_source FROM linnworks.orders;
SELECT DISTINCT vendor FROM linnworks.orders;
SELECT DISTINCT status FROM linnworks.orders;
```

No business logic methods needed — this is a denormalized data record, not a rich entity.

### Phase 2: Application Layer

**2.1 Contracts**
- `app/Application/Contracts/Linnworks/OrderClientInterface.php` — NEW
- `app/Application/Contracts/Linnworks/LinnworksOrderRepositoryInterface.php` — NEW

```php
interface OrderClientInterface {
    /**
     * Iterate processed orders updated since fromDate in batches.
     *
     * Token pagination handled internally. Yields batches of ~200 orders.
     * Same pattern as InventoryClientInterface::iterateStockItemBatches().
     *
     * @return Generator<int, list<LinnworksOrder>, mixed, void>
     */
    public function iterateProcessedOrders(DateTimeImmutable $fromDate): Generator;

    public function getOrderById(Guid $orderId): LinnworksOrder;
}

interface LinnworksOrderRepositoryInterface extends RepositoryWriteInterface {
    // Inherits save(), saveMany() from RepositoryWriteInterface
}
```

**2.2 SyncCursorType**
- `app/Application/Enums/SyncCursorType.php` — MODIFY

Add case: `LinnworksOrdersCursor = 'linnworks_orders_cursor'`

**2.3 Result Type**
- `app/Application/Linnworks/Results/OrderSyncResult.php` — NEW (wraps sync outcome)

```php
final readonly class OrderSyncResult {
    public function __construct(
        public int $fetched,
        public int $saved,
        public int $failed,
        public ?DateTimeImmutable $latestLastUpdated, // For cursor advancement
        public array $failedReferences = [],
    ) {}
}
```

Note: `GetOrdersResult` is **not needed** — pagination is internal to the Infrastructure client (Generator pattern). No API pagination details leak into Application.

**2.4 SyncTier Enum**
- `app/Application/Linnworks/Enums/OrderSyncTier.php` — NEW

```php
enum OrderSyncTier: string {
    case Hourly = 'hourly';   // 1 hour lookback
    case Daily = 'daily';     // 2 days lookback
    case Weekly = 'weekly';   // 2 weeks lookback
    case Full = 'full';       // 90 days lookback

    /** Calculate fromDate in handle(), not constructor (Octane safety). */
    public function fromDate(): DateTimeImmutable { /* now minus lookback window */ }
}
```

**2.5 Use Cases**
- `app/Application/Linnworks/UseCases/SyncLinnworksOrdersUseCase.php` — NEW
- `app/Application/Linnworks/UseCases/SyncLinnworksCursorUseCase.php` — NEW

**SyncLinnworksOrdersUseCase** — Core sync logic shared by all 5 tiers:
```php
final readonly class SyncLinnworksOrdersUseCase {
    public function execute(DateTimeImmutable $fromDate): OrderSyncResult
    {
        // 1. Iterate batches from client Generator (pagination is internal)
        // 2. Buffer N pages, then flush via repository.saveManyBulk()
        // 3. Track max LastUpdated across all orders in each batch
        // 4. Continue-on-failure: partial save failures logged, cursor still advances
        // 5. Return OrderSyncResult with fetched/saved/failed/latestLastUpdated
    }
}
```
Pattern: Follows `SyncAllStockItemsUseCase` — iterate Generator, buffer pages, flush batches. Cursor advances even with partial failures (safe: idempotent upserts + redundancy tiers retry missed items).

**SyncLinnworksCursorUseCase** — Cursor management wrapper:
```php
final readonly class SyncLinnworksCursorUseCase {
    public function execute(): OrderSyncResult
    {
        // 1. Read cursor from SyncCursorRepository (LinnworksOrdersCursor)
        // 2. Resolve fromDate (cursor value or DEFAULT_LOOKBACK if null)
        // 3. Cap lookback to MAX_LOOKBACK_HOURS if cursor is stale
        // 4. Delegate to SyncLinnworksOrdersUseCase(fromDate)
        // 5. If result.latestLastUpdated exists, advance cursor
        // 6. Return result
    }
}
```

Pattern: `SyncDeltaStockToShopwiredUseCase` for cursor logic, `SyncAllStockItemsUseCase` for batch iteration.

**2.6 Jobs**
- `app/Application/Jobs/Linnworks/SyncLinnworksOrdersByCursorJob.php` — NEW
- `app/Application/Jobs/Linnworks/SyncLinnworksOrdersJob.php` — NEW

| Property | CursorJob | OrdersJob |
|----------|-----------|-----------|
| Queue | `default` | `low` |
| Timeout | 90s | 3600s |
| Tries | 2 | 2 |
| Backoff | [30] | [60] |
| ShouldBeUnique | Yes (`uniqueFor=120`) | Yes (uniqueId includes tier) |
| Input | None | `OrderSyncTier` in constructor |
| Calls | `SyncLinnworksCursorUseCase` | `SyncLinnworksOrdersUseCase` |
| Date calc | Cursor manages | `tier.fromDate()` in `handle()` |

### Phase 3: Database + Model

**3.1 Migration**
- `database/migrations/XXXX_create_linnworks_orders_table.php` — NEW

Schema derived from property audit recommendations (flattened GeneralInfo, TotalsInfo, ShippingInfo, CustomerInfo):

```
linnworks.orders
├── id                      UUID PK (Laravel)
├── linnworks_order_id      UUID UNIQUE (Linnworks GUID — upsert key)
├── num_order_id            INTEGER
├── processed               BOOLEAN
├── last_updated            TIMESTAMPTZ NOT NULL (cursor field, indexed)
├── processed_on            TIMESTAMPTZ NULL
├── paid_on                 TIMESTAMPTZ NULL
├── received_date           TIMESTAMPTZ NULL
│
├── ── GeneralInfo (flattened) ──
├── reference_num           VARCHAR(50) (most-used field in legacy)
├── external_reference_num  VARCHAR(100)
├── secondary_reference     VARCHAR(100)
├── status                  SMALLINT
├── hold_or_cancel          BOOLEAN DEFAULT FALSE
├── marker                  SMALLINT NULL
├── is_parked               BOOLEAN DEFAULT FALSE
├── source                  VARCHAR(50)
├── sub_source              VARCHAR(50)
├── despatch_by_date        TIMESTAMPTZ NULL
├── fulfilment_location_id  UUID NULL
├── location                UUID NULL
├── folder_names            JSONB DEFAULT '[]' (string array)
│
├── ── TotalsInfo (flattened) ──
├── total_charge            DECIMAL(10,2) DEFAULT 0
├── subtotal                DECIMAL(10,2) DEFAULT 0
├── tax                     DECIMAL(10,2) DEFAULT 0
├── payment_method          VARCHAR(100)
│
├── ── ShippingInfo (flattened) ──
├── postal_service_name     VARCHAR(100)
├── vendor                  VARCHAR(100)
├── postage_cost            DECIMAL(10,2) DEFAULT 0
├── postage_cost_ex_tax     DECIMAL(10,2) DEFAULT 0
├── tracking_number         VARCHAR(200)
│
├── ── CustomerInfo (flattened, no separate table) ──
├── channel_buyer_name      VARCHAR(200)
├── ship_email              VARCHAR(200)
├── ship_full_name          VARCHAR(200)
├── ship_company            VARCHAR(200)
├── ship_address1           VARCHAR(200)
├── ship_address2           VARCHAR(200)
├── ship_address3           VARCHAR(200)
├── ship_town               VARCHAR(100)
├── ship_postcode           VARCHAR(20)
├── ship_country            VARCHAR(100)
├── bill_email              VARCHAR(200)
├── bill_full_name          VARCHAR(200)
├── bill_company            VARCHAR(200)
├── bill_address1           VARCHAR(200)
├── bill_address2           VARCHAR(200)
├── bill_address3           VARCHAR(200)
├── bill_town               VARCHAR(100)
├── bill_postcode           VARCHAR(20)
├── bill_country            VARCHAR(100)
│
├── created_at              TIMESTAMPTZ
└── updated_at              TIMESTAMPTZ
```

**Indexes:** `linnworks_order_id` (unique), `last_updated`, `received_date`, `num_order_id`
**Note:** All string columns nullable unless marked NOT NULL — the API may omit fields.

**3.2 Eloquent Model**
- `app/Infrastructure/Linnworks/Models/LinnworksOrderModel.php` — NEW

Pattern: `HasUuids`, `$guarded = []`, `$table = 'linnworks.orders'`, immutable datetime casts, `folder_names` cast to `array`.

### Phase 4: Infrastructure API + Repository

**4.1 Response DTOs**
- `app/Infrastructure/Linnworks/Responses/GetOrdersApiResponse.php` — NEW (top-level wrapper)
- `app/Infrastructure/Linnworks/Responses/OrderResponse.php` — NEW (individual order)
- `app/Infrastructure/Linnworks/Responses/OrderGeneralInfoResponse.php` — NEW (nested)
- `app/Infrastructure/Linnworks/Responses/OrderTotalsInfoResponse.php` — NEW (nested)
- `app/Infrastructure/Linnworks/Responses/OrderShippingInfoResponse.php` — NEW (nested)
- `app/Infrastructure/Linnworks/Responses/OrderCustomerInfoResponse.php` — NEW (nested)
- `app/Infrastructure/Linnworks/Responses/OrderAddressResponse.php` — NEW (nested)

The v2 API returns nested objects (GeneralInfo, TotalsInfo, etc.) — each gets its own Spatie DTO. The top-level `OrderResponse` implements `DomainConvertibleInterface` with `toDomain()` that flattens everything into `LinnworksOrder`.

```php
// Top-level response (not DomainConvertible — it's a pagination wrapper)
final class GetOrdersApiResponse extends Data {
    public function __construct(
        public readonly int $TotalOrders,
        public readonly ?string $NextSearchToken,
        #[DataCollectionOf(OrderResponse::class)]
        public readonly ?array $ProcessedOrders,
    ) {}
}

// Individual order DTO
final class OrderResponse extends Data implements DomainConvertibleInterface {
    public function __construct(
        public readonly string $OrderId,
        public readonly int $NumOrderId,
        public readonly bool $Processed,
        public readonly ?string $ProcessedOn,
        public readonly ?string $PaidOn,
        public readonly string $LastUpdated,
        public readonly OrderGeneralInfoResponse $GeneralInfo,
        public readonly OrderTotalsInfoResponse $TotalsInfo,
        public readonly OrderShippingInfoResponse $ShippingInfo,
        public readonly OrderCustomerInfoResponse $CustomerInfo,
        /** @var list<string> */
        public readonly array $FolderName = [],
    ) {}

    public function toDomain(): LinnworksOrder { /* flatten all nested DTOs */ }
}
```

**4.2 API Client**
- `app/Infrastructure/Linnworks/Clients/OrderClient.php` — NEW

Uses `LinnworksTransportInterface` (existing `get()` method for `GET /v2/orders`).
Token pagination handled internally — same pattern as `InventoryClient::iterateStockItemBatches()`.

```php
final readonly class OrderClient implements OrderClientInterface {
    use LinnworksResponseParserTrait;

    /** @return Generator<int, list<LinnworksOrder>, mixed, void> */
    public function iterateProcessedOrders(DateTimeImmutable $fromDate): Generator
    {
        $searchToken = null;
        $page = 0;

        do {
            $apiResponse = $this->fetchPage($fromDate, $searchToken);
            $orders = array_map(
                fn(OrderResponse $dto) => $dto->toDomain(),
                $apiResponse->ProcessedOrders ?? [],
            );

            if ($orders !== []) {
                yield $page => $orders;
            }

            $searchToken = $apiResponse->NextSearchToken;
            $page++;
        } while ($searchToken !== null);
    }

    private function fetchPage(DateTimeImmutable $fromDate, ?string $searchToken): GetOrdersApiResponse
    {
        $response = $this->transport->get('/v2/orders', [
            'fromDate' => $fromDate->format('c'),
            'entriesPerPage' => 200,
            'includeProcessed' => 'true',
            'searchToken' => $searchToken,
        ]);

        return GetOrdersApiResponse::from($response->json());
        // CannotCreateData caught and translated to InvalidApiResponseException
    }

    public function getOrderById(Guid $orderId): LinnworksOrder
    {
        // Uses same endpoint with id parameter (overrides all other filters)
        $response = $this->transport->get('/v2/orders', ['id' => [$orderId->value]]);
        // Parse, validate exactly 1 processed order returned, confirm ID matches
    }
}
```

**4.3 Repository**
- `app/Infrastructure/Linnworks/Repositories/EloquentLinnworksOrderRepository.php` — NEW

Extends `AbstractEloquentRepository<LinnworksOrder>`. Uses `saveManyBulk()` for batch upserts.
- `getModelClass()` → `LinnworksOrderModel::class`
- `getEntityIdentifier()` → `$order->orderId` (Linnworks GUID)
- `entityToAttributes()` → maps all domain fields to model columns (flat mapping)
- `getUpsertKeys()` → `['linnworks_order_id']`

### Phase 5: Wiring

**5.1 Service Provider**
- `app/Providers/LinnworksServiceProvider.php` — MODIFY

Add bindings:
- `OrderClientInterface` → `OrderClient`
- `LinnworksOrderRepositoryInterface` → `EloquentLinnworksOrderRepository`

**5.2 Schedule**
- `app/Providers/Schedule/LinnworksScheduleServiceProvider.php` — MODIFY

```php
// Cursor: every minute
Schedule::job(new SyncLinnworksOrdersByCursorJob())
    ->name('sync-linnworks-orders-cursor')
    ->everyMinute()
    ->onOneServer()
    ->withoutOverlapping();

// Hourly: orders updated in last hour
Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Hourly))
    ->name('sync-linnworks-orders-hourly')
    ->hourly()
    ->onOneServer()
    ->withoutOverlapping(15);

// Daily: orders updated in last 2 days
Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Daily))
    ->name('sync-linnworks-orders-daily')
    ->daily()
    ->onOneServer()
    ->withoutOverlapping(30);

// Weekly: orders updated in last 2 weeks
Schedule::job(new SyncLinnworksOrdersJob(OrderSyncTier::Weekly))
    ->name('sync-linnworks-orders-weekly')
    ->weekly()
    ->onOneServer()
    ->withoutOverlapping(60);

// Full: quarterly backfill (manual dispatch initially, add schedule later)
```

---

## Files Summary

| # | Action | File | Layer |
|---|--------|------|-------|
| 1 | NEW | `app/Domain/Linnworks/ValueObjects/LinnworksOrder.php` | Domain |
| 2 | NEW | `app/Application/Contracts/Linnworks/OrderClientInterface.php` | Application |
| 3 | NEW | `app/Application/Contracts/Linnworks/LinnworksOrderRepositoryInterface.php` | Application |
| 4 | MODIFY | `app/Application/Enums/SyncCursorType.php` | Application |
| 5 | NEW | `app/Application/Linnworks/Results/OrderSyncResult.php` | Application |
| 6 | NEW | `app/Application/Linnworks/Enums/OrderSyncTier.php` | Application |
| 7 | NEW | `app/Application/Linnworks/UseCases/SyncLinnworksOrdersUseCase.php` | Application |
| 8 | NEW | `app/Application/Linnworks/UseCases/SyncLinnworksCursorUseCase.php` | Application |
| 9 | NEW | `app/Application/Jobs/Linnworks/SyncLinnworksOrdersByCursorJob.php` | ApplicationJobs |
| 10 | NEW | `app/Application/Jobs/Linnworks/SyncLinnworksOrdersJob.php` | ApplicationJobs |
| 11 | NEW | `database/migrations/XXXX_create_linnworks_orders_table.php` | Infrastructure |
| 12 | NEW | `app/Infrastructure/Linnworks/Models/LinnworksOrderModel.php` | Infrastructure |
| 13 | NEW | `app/Infrastructure/Linnworks/Responses/GetOrdersApiResponse.php` | Infrastructure |
| 14 | NEW | `app/Infrastructure/Linnworks/Responses/OrderResponse.php` | Infrastructure |
| 15 | NEW | `app/Infrastructure/Linnworks/Responses/OrderGeneralInfoResponse.php` | Infrastructure |
| 16 | NEW | `app/Infrastructure/Linnworks/Responses/OrderTotalsInfoResponse.php` | Infrastructure |
| 17 | NEW | `app/Infrastructure/Linnworks/Responses/OrderShippingInfoResponse.php` | Infrastructure |
| 18 | NEW | `app/Infrastructure/Linnworks/Responses/OrderCustomerInfoResponse.php` | Infrastructure |
| 19 | NEW | `app/Infrastructure/Linnworks/Responses/OrderAddressResponse.php` | Infrastructure |
| 20 | NEW | `app/Infrastructure/Linnworks/Clients/OrderClient.php` | Infrastructure |
| 21 | NEW | `app/Infrastructure/Linnworks/Repositories/EloquentLinnworksOrderRepository.php` | Infrastructure |
| 22 | MODIFY | `app/Providers/LinnworksServiceProvider.php` | Providers |
| 23 | MODIFY | `app/Providers/Schedule/LinnworksScheduleServiceProvider.php` | Providers |

**Total: 20 new files, 3 modified files**

**Deferred to follow-up PR** (after backsync reveals value space):
- `app/Domain/Linnworks/Enums/OrderSource.php`
- `app/Domain/Linnworks/Enums/OrderStatus.php`
- `app/Domain/Linnworks/Enums/ShippingVendor.php`

---

## Reusable Existing Code

| What | Location | Usage |
|------|----------|-------|
| `LinnworksTransportInterface` | `Infrastructure/Linnworks/Contracts/` | HTTP transport (`get()` for v2 endpoint) |
| `LinnworksResponseParserTrait` | `Infrastructure/Linnworks/Support/` | CannotCreateData handling in client |
| `AbstractEloquentRepository` | `Infrastructure/Repositories/` | Base for order repository (`saveManyBulk`) |
| `SyncCursorRepositoryInterface` | `Application/Contracts/Inventory/` | Cursor persistence (existing impl) |
| `SyncCursorModel` | `Infrastructure/Database/Models/` | Existing cursor table — no migration needed |
| `EloquentGateway` | `Infrastructure/Persistence/` | `batchUpsertMany()` for bulk upserts |
| `Guid` value object | `Domain/ValueObjects/` | For `linnworks_order_id` |
| Pattern A exception handling | `Jobs/Linnworks/SyncLinnworksStockItemsJob.php` | Template for both jobs |
| `DomainConvertibleInterface` | `Infrastructure/Contracts/` | Marker for DTOs with `toDomain()` |
| `SyncDeltaStockToShopwiredUseCase` | `Application/Inventory/UseCases/` | Reference for cursor management pattern |

---

## Verification

### Phase 0: API Validation (do first — zero new code)
1. In tinker, use existing `LinnworksTransportInterface` to call `/v2/orders`
2. `dd($response->json())` — confirm field names, nested structure, pagination token
3. If fields diverge from property audit, adjust DTOs/migration before proceeding

### Automated Tests
1. **Use case tests**: Mock client Generator + repository; verify batch iteration, cursor advancement, continue-on-failure
2. **Job tests**: Mock use case; verify Pattern A exception handling, uniqueId per tier
3. **Repository tests**: Mock gateway; verify `entityToAttributes()` mapping covers all columns
4. **DTO tests**: Test `toDomain()` with sample API response payloads

### Integration Validation
1. Run migration: `php artisan migrate`
2. Dispatch cursor job: `SyncLinnworksOrdersByCursorJob::dispatch()`
3. Verify orders appear in `linnworks.orders` table
4. Dispatch tier job: `SyncLinnworksOrdersJob::dispatch(OrderSyncTier::Daily)`
5. Verify `make lint` passes (PHPStan, PHPArkitect, Deptrac)

---

## Implementation Order

Build from inside-out, but **start with Phase 0** to validate the endpoint:

```
Phase 0: Raw transport call in tinker → validate API response structure
    ↓ (confirmed API works)
Phase 1: LinnworksOrder domain value object (strings, no enums)
Phase 2: Application contracts, results, enums, cursor type
Phase 3: Migration + Eloquent model
Phase 4: Full response DTOs + complete OrderClient + repository
Phase 5: Use cases (orders + cursor)
Phase 6: Jobs
Phase 7: Schedule + service provider wiring
```

Each phase can be committed independently. Phase 0 is deliberately first to fail fast if the endpoint doesn't behave as documented.
