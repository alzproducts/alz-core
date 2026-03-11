# ShopWired Webhook System

## Context

Currently ShopWired data is synced via pull-based scheduled jobs (cron → fetch from API → save to DB). This works but introduces latency — changes in ShopWired aren't reflected until the next sync cycle. Adding webhook support enables near-real-time updates with an idempotent, out-of-order-safe design. The architecture uses a partial-update-then-reconcile pattern: apply what the webhook provides immediately, then queue a full API refresh to fill in any missing subentities.

---

## Architecture Overview

```
ShopWired → POST /api/shopwired/webhooks/{entity}
         → [throttle:webhooks 300/min]
         → [VerifyShopwiredWebhookSignature middleware]
         → Controller (routes by topic)
         → SyncXUseCase or DeleteXUseCase
              ├─ staleness check (>24h → discard)
              ├─ idempotency check (shopwired_webhook_at)
              ├─ save via repository (partial update)
              └─ dispatch RefreshXJob (full API reconciliation)
```

---

## Implementation Steps

### Step 1: Database Migration

**New file:** `database/migrations/YYYY_MM_DD_HHMMSS_add_shopwired_webhook_at_to_shopwired_tables.php`

Add `shopwired_webhook_at` column (nullable `timestampTz`) to:
- `shopwired.orders`
- `shopwired.products`
- `shopwired.customers`

This is separate from `updated_at` (Laravel sync time) and `shopwired_updated_at`/`order_placed_at` (ShopWired business time). It tracks the most recent webhook event timestamp processed, enabling idempotency.

### Step 2: Config & Rate Limiting

**Modify:** `config/shopwired.php`
- Add `'webhook_secret' => env('SHOPWIRED_WEBHOOK_SECRET')`

**Modify:** `app/Providers/RateLimitServiceProvider.php` (line 48)
- Change `Limit::perMinute(100)` → `Limit::perMinute(300)` for the `webhooks` limiter

### Step 3: Enums

**New:** `app/Infrastructure/Shopwired/Enums/WebhookSubjectType.php`
```php
enum WebhookSubjectType: string
{
    case Order = 'order';
    case OrderRefund = 'order_refund';
    case Product = 'product';
    case Customer = 'customer';
    case Category = 'category';
    case Brand = 'brand';
    case Tag = 'tag';
    case Batch = 'batch';
}
```

**New:** `app/Infrastructure/Shopwired/Enums/WebhookTopic.php`
```php
enum WebhookTopic: string
{
    // Orders (from docs)
    case OrderUpdated = 'order.updated';
    case OrderDeleted = 'order.deleted';
    case OrderFinalized = 'order.finalized';
    case OrderStatusChanged = 'order.status_changed';

    // Order Refunds
    case OrderRefundCreated = 'order.refund.created';

    // Products
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductDeleted = 'product.deleted';
    case ProductStockChanged = 'product.stock_changed';

    // Customers
    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';
    case CustomerDeleted = 'customer.deleted';

    // Categories
    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryDeleted = 'category.deleted';

    // Brands
    case BrandCreated = 'brand.created';
    case BrandUpdated = 'brand.updated';
    case BrandDeleted = 'brand.deleted';

    // Tags
    case TagCreated = 'tag.created';
    case TagUpdated = 'tag.updated';
    case TagDeleted = 'tag.deleted';

    // Batch
    case BatchCompleted = 'batch.completed';

    public function subjectType(): WebhookSubjectType { ... }
    public function isDeleteEvent(): bool { ... }
}
```

### Step 4: Webhook Signature Verification Middleware

**New:** `app/Presentation/Http/Middleware/VerifyShopwiredWebhookSignature.php`

Flow:
1. Read raw request body (`$request->getContent()`)
2. Get secret from `config('shopwired.webhook_secret')`
3. Compute `hash_hmac('sha256', $body, $secret)`
4. Compare with `X-ShopWired-Signature` header using `hash_equals()` (timing-safe)
5. **Verification token handling**: If body contains `verificationToken` key, respond with `hash_hmac('sha256', $token, $secret)` and short-circuit (200 OK)
6. If signature invalid → abort 403

**Key detail**: Verification token check happens AFTER signature verification — both request types are HMAC-signed according to the docs' code sample.

### Step 5: Webhook DTO (Infrastructure)

**New:** `app/Infrastructure/Shopwired/Responses/WebhookEventResponse.php`

Spatie Data DTO to parse the incoming webhook payload. Based on docs, the root structure has `timestamp` and `event`:
```php
final class WebhookEventResponse extends Data
{
    public function __construct(
        public readonly string $timestamp,           // ISO 8601
        public readonly WebhookEventPayload $event,  // nested DTO
    ) {}
}
```

**New:** `app/Infrastructure/Shopwired/Responses/WebhookEventPayload.php`
```php
final class WebhookEventPayload extends Data
{
    public function __construct(
        public readonly int $id,                          // payload ID for logging
        public readonly string $createdAt,                // event creation time
        public readonly WebhookTopic $topic,              // enum: WebhookTopic::OrderUpdated etc.
        public readonly WebhookSubjectType $subjectType,  // enum: WebhookSubjectType::Order etc.
        public readonly int $subjectId,                   // e.g. 6621880
        public readonly ?array $data,                     // {object: {...}}
    ) {}
}
```

Spatie Data auto-casts string-backed enums from JSON — `"order.updated"` → `WebhookTopic::OrderUpdated`.

### Step 6: Controllers (Presentation Layer)

**New files in** `app/Presentation/Http/Controllers/Shopwired/Webhooks/`:

1. `ShopwiredWebhookOrderController.php`
2. `ShopwiredWebhookProductController.php`
3. `ShopwiredWebhookCustomerController.php`

Each controller routes to the correct use case based on topic:
```php
public function __invoke(Request $request): JsonResponse
{
    $webhookEvent = WebhookEventResponse::from($request->all());

    match ($webhookEvent->event->topic) {
        WebhookTopic::OrderDeleted => $this->deleteUseCase->execute($webhookEvent),
        WebhookTopic::OrderStatusChanged => $this->updateStatusUseCase->execute($webhookEvent),
        WebhookTopic::OrderRefundCreated => $this->createRefundUseCase->execute($webhookEvent),
        default => $this->syncUseCase->execute($webhookEvent),
    };

    return response()->json(status: 200);
}
```

Product controller similarly routes `ProductStockChanged` → `UpdateProductStockUseCase`.

Controllers are thin — parse DTO, route to use case, return 200. No try-catch (handled by global exception handler). Must respond within 5 seconds.

### Step 7: Routes

**Modify:** `routes/api.php`

Add within the existing `shopwired` prefix group:
```php
Route::prefix('webhooks')
    ->middleware(['throttle:webhooks', VerifyShopwiredWebhookSignature::class])
    ->group(static function (): void {
        Route::post('orders', ShopwiredWebhookOrderController::class);
        Route::post('products', ShopwiredWebhookProductController::class);
        Route::post('customers', ShopwiredWebhookCustomerController::class);
    });
```

No JWT auth — webhooks are authenticated via HMAC signature.

### Step 8: Use Cases (Application Layer)

#### Event Categorization

| Category | Events | Payload | Use Case |
|----------|--------|---------|----------|
| **Standard** | `order.updated`, `order.finalized` | Full entity in `data.object` | `SyncOrderUseCase` |
| **Standard** | `product.created`, `product.updated` | Full entity in `data.object` | `SyncProductUseCase` |
| **Standard** | `customer.created`, `customer.updated` | Full entity in `data.object` | `SyncCustomerUseCase` |
| **Custom** | `order.status_changed` | `{newStatus: {id, name, type, sortOrder}}` | `UpdateOrderStatusUseCase` |
| **Custom** | `order.refund.created` | `{id, orderId, createdAt, amount, description}` | `CreateOrderRefundUseCase` |
| **Custom** | `product.stock_changed` | `{sku, isVariation, newQuantity, orderId?}` | `UpdateProductStockUseCase` |
| **Delete** | `order.deleted` | Full entity in `data.object` | `DeleteOrderUseCase` |
| **Delete** | `product.deleted` | Full entity in `data.object` | `DeleteProductUseCase` |
| **Delete** | `customer.deleted` | Full entity in `data.object` | `DeleteCustomerUseCase` |

Note: `order.refund.created` has subject type `order_refund` (not `order`), but routes to the order controller since refunds are children of orders.

**New files in** `app/Application/UseCases/Shopwired/Webhooks/`:

#### Sync Use Cases (3) — Standard Events

- `SyncOrderUseCase.php` — handles `order.updated`, `order.finalized`
- `SyncProductUseCase.php` — handles `product.created`, `product.updated`
- `SyncCustomerUseCase.php` — handles `customer.created`, `customer.updated`

Shared logic pattern:
```
1. Extract event timestamp from webhook
2. STALENESS: if timestamp > 24 hours old → log + discard
3. IDEMPOTENCY: query DB for shopwired_webhook_at of this entity
   - If exists AND shopwired_webhook_at >= event timestamp → log + discard
4. Parse data.object via existing Response DTOs (OrderResponse/ProductResponse/CustomerResponse)
5. Map to Domain entity using existing pipeline
6. Save via repository (existing save() — now with null-aware child syncing)
7. Dispatch RefreshXJob with subjectId and event timestamp
```

#### Custom Event Use Cases (2) — Special Payloads

- `UpdateOrderStatusUseCase.php` — handles `order.status_changed`
- `CreateOrderRefundUseCase.php` — handles `order.refund.created`
- `UpdateProductStockUseCase.php` — handles `product.stock_changed`

These events have lightweight payloads that don't match the standard Response DTOs. They need dedicated DTOs and targeted repository update methods.

**`UpdateOrderStatusUseCase` flow:**
```
1. STALENESS + IDEMPOTENCY checks (same as sync)
2. Parse custom payload → OrderStatusChangedData DTO
3. Update status fields directly: status_id, status_name, status_type, status_sort_order
4. Update shopwired_webhook_at
5. Dispatch RefreshShopwiredOrderJob for full reconciliation
```

**`CreateOrderRefundUseCase` flow:**
```
1. STALENESS check (same as sync)
2. Parse custom payload → OrderRefundCreatedData DTO
3. Insert refund into shopwired.order_refunds (by orderId → order external_id)
4. Dispatch RefreshShopwiredOrderJob for full reconciliation (fills in any missing fields)
```

Note: Idempotency for refunds uses the refund `id` field (not `shopwired_webhook_at`) — check if refund with this external ID already exists before inserting.

**`UpdateProductStockUseCase` flow:**
```
1. STALENESS + IDEMPOTENCY checks (same as sync)
2. Parse custom payload → ProductStockChangedData DTO
3. If isVariation=false → update product stock by SKU
   If isVariation=true → update variation stock by SKU
4. Update shopwired_webhook_at on the parent product
5. Dispatch RefreshShopwiredProductJob for full reconciliation
```

**New DTOs** (Infrastructure/Responses):
- `OrderStatusChangedData.php` — `{newStatus: {id: int, name: string, type: string, sortOrder: int}}`
- `OrderRefundCreatedData.php` — `{id: int, orderId: int, createdAt: string, amount: float, description: string}`
- `ProductStockChangedData.php` — `{sku: string, isVariation: bool, newQuantity: int, orderId: ?int}`

**New repository methods** (targeted partial updates):
- `OrderRepository::updateStatus(int $externalId, int $statusId, string $statusName, string $statusType, int $sortOrder): void`
- `OrderRepository::addRefund(int $orderExternalId, int $refundExternalId, float $amount, string $description, DateTimeImmutable $createdAt): void`
- `ProductRepository::updateStock(string $sku, bool $isVariation, int $newQuantity): void`

All log entries include `['webhook_id' => $event->id, 'subject_id' => $event->subjectId, 'topic' => $event->topic]`.

#### Delete Use Cases (3)

- `DeleteOrderUseCase.php` — handles `order.deleted`
- `DeleteProductUseCase.php` — handles `product.deleted`
- `DeleteCustomerUseCase.php` — handles `customer.deleted`

Simpler flow (delete events include full entity in `data.object`, but we only need `subjectId`):
```
1. STALENESS: if timestamp > 24 hours old → log + discard
2. Delete from DB by external_id (hard delete, cascades to children via FK)
3. Log deletion
```

No reconciliation needed. Idempotent — if record doesn't exist, log and return silently.

### Step 9: Repository Changes — Partial Update Support

**Convention**: `null` = "not provided, don't touch existing children" | `[]` = "explicitly empty, delete all children"

This extends the two-mode pattern already used by `Order.products` and `Order.customFields` to ALL child relations across all entities.

#### 9a. Domain Entity Changes

**Modify:** `app/Domain/Catalog/Order/ValueObjects/Order.php`
- `discounts`: `array $discounts = []` → `?array $discounts = null`
- `refunds`: `array $refunds = []` → `?array $refunds = null`
- `adminComments`: `array $adminComments = []` → `?array $adminComments = null`
- Update methods: `hasDiscounts()`, `totalDiscountValue()`, `hasRefunds()`, `totalRefundValue()`, `hasAdminComments()` — add null guards
- `products` and `customFields` already nullable ✅

**Modify:** `app/Domain/Catalog/Product/ValueObjects/Product.php`
- `variations`: `array $variations` → `?array $variations = null`
- Update methods: `hasVariations()`, `totalStock()` — add null guards

#### 9b. Repository Changes

**Modify:** `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php`

Wrap ALL child sync calls in null guards:
```php
if ($entity->products !== null) {
    $this->syncProducts($orderUuid, $entity);
}
if ($entity->discounts !== null) {
    $this->syncDiscounts($orderUuid, $entity);
}
if ($entity->refunds !== null) {
    $this->syncRefunds($orderUuid, $entity);
}
if ($entity->adminComments !== null) {
    $this->syncAdminComments($orderUuid, $entity);
}
```

**Modify:** `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

Wrap variation sync in null guard:
```php
if ($product->variations !== null) {
    $this->syncVariations($product);
}
```

#### 9c. Existing API Sync Paths (backward compatibility)

Existing code that creates entities from API responses always provides full arrays → no behavior change. Only webhook-created entities will have null subentities.

Verify: all existing factories/mappers (OrderModelMapper, ProductDomainFactory, etc.) pass explicit arrays for all child relations.

#### 9d. Delete Methods & Model Updates

**Add to all 3 repositories:** `deleteByExternalId(int $externalId): void` method for the delete use cases. Product already has `deleteByExternalIds(array $ids)` — add a single-entity variant. Order and Customer need new delete methods.

**Modify:** Model `$fillable`/`$casts` arrays to include `shopwired_webhook_at` (cast to `immutable_datetime`).

### Step 10: Reconciliation Jobs

**New files in** `app/Application/Jobs/Shopwired/`:

- `RefreshShopwiredOrderJob.php`
- `RefreshShopwiredProductJob.php`
- `RefreshShopwiredCustomerJob.php`

Pattern (follows existing job conventions):
```php
final class RefreshShopwiredOrderJob implements ShouldBeUnique, ShouldQueue
{
    public int $tries = 3;
    public array $backoff = [30, 60, 120];
    public int $timeout = 90;
    public int $uniqueFor = 300;

    public function __construct(
        private readonly int $subjectId,
        private readonly string $webhookTimestamp,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return 'refresh-order-' . $this->subjectId;
    }

    public function handle(OrderClientInterface $client, OrderRepositoryInterface $repo): void
    {
        // 1. Check if a newer webhook has arrived since dispatch
        // 2. If stale → skip (another refresh is coming or already ran)
        // 3. Fetch full entity from API: $client->getOrderById($this->subjectId)
        // 4. Save via repository: $repo->save($order)
    }
}
```

`ShouldBeUnique` with `uniqueId` based on entity ID prevents redundant API calls when multiple webhooks arrive for the same entity in quick succession.

### Step 11: Webhook Health Check (GET /webhooks API + Daily Job)

#### New API Client Method

**New:** `app/Infrastructure/Shopwired/Clients/WebhookClient.php`

```php
final class WebhookClient
{
    public function listWebhooks(): array  // returns list of WebhookStatusResponse
    {
        // GET /webhooks via ShopwiredHttpTransport
    }
}
```

**New:** `app/Application/Contracts/Shopwired/WebhookClientInterface.php`

**New:** `app/Infrastructure/Shopwired/Responses/WebhookStatusResponse.php`
```php
final class WebhookStatusResponse extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $topic,
        public readonly string $url,
        public readonly bool $enabled,
        public readonly bool $verified,
    ) {}
}
```

#### Daily Health Check Job

**New:** `app/Application/Jobs/Shopwired/CheckShopwiredWebhookHealthJob.php`

Flow:
1. Call `WebhookClient::listWebhooks()`
2. Filter for webhooks where `enabled === false` OR `verified === false`
3. If any found → dispatch `DisabledWebhooksNotification`

#### Slack Notification

**New:** `app/Infrastructure/Notifications/Slack/DisabledWebhooksNotification.php`

Following pattern from `VariantSkusGeneratedNotification`:
- Header: "ShopWired Webhook Alert"
- Section: List of disabled/unverified webhooks with topics
- Section: Link to `https://admin.myshopwired.uk/business/api-webhooks`
- Context: Check timestamp

#### Schedule

**Modify:** `app/Providers/Schedule/ShopwiredScheduleServiceProvider.php`

Add:
```php
Schedule::job(new CheckShopwiredWebhookHealthJob())
    ->name('check-shopwired-webhook-health')
    ->dailyAt('03:00')
    ->timezone('Europe/London')
    ->onOneServer();
```

### Step 12: Service Provider Registration

**Modify:** `app/Providers/ShopwiredServiceProvider.php`

- Register `WebhookClient` as singleton
- Bind `WebhookClientInterface` → `WebhookClient`

---

## Files Summary

### New Files (~27)
| File | Layer |
|------|-------|
| Migration: add `shopwired_webhook_at` | Database |
| `WebhookSubjectType.php` | Infrastructure/Enums |
| `WebhookTopic.php` | Infrastructure/Enums |
| `VerifyShopwiredWebhookSignature.php` | Presentation/Middleware |
| `WebhookEventResponse.php` | Infrastructure/Responses |
| `WebhookEventPayload.php` | Infrastructure/Responses |
| `WebhookStatusResponse.php` | Infrastructure/Responses |
| `OrderStatusChangedData.php` | Infrastructure/Responses |
| `OrderRefundCreatedData.php` | Infrastructure/Responses |
| `ProductStockChangedData.php` | Infrastructure/Responses |
| `ShopwiredWebhookOrderController.php` | Presentation/Controllers |
| `ShopwiredWebhookProductController.php` | Presentation/Controllers |
| `ShopwiredWebhookCustomerController.php` | Presentation/Controllers |
| `SyncOrderUseCase.php` | Application/UseCases |
| `SyncProductUseCase.php` | Application/UseCases |
| `SyncCustomerUseCase.php` | Application/UseCases |
| `UpdateOrderStatusUseCase.php` | Application/UseCases |
| `CreateOrderRefundUseCase.php` | Application/UseCases |
| `UpdateProductStockUseCase.php` | Application/UseCases |
| `DeleteOrderUseCase.php` | Application/UseCases |
| `DeleteProductUseCase.php` | Application/UseCases |
| `DeleteCustomerUseCase.php` | Application/UseCases |
| `RefreshShopwiredOrderJob.php` | Application/Jobs |
| `RefreshShopwiredProductJob.php` | Application/Jobs |
| `RefreshShopwiredCustomerJob.php` | Application/Jobs |
| `CheckShopwiredWebhookHealthJob.php` | Application/Jobs |
| `WebhookClient.php` | Infrastructure/Clients |
| `WebhookClientInterface.php` | Application/Contracts |
| `DisabledWebhooksNotification.php` | Infrastructure/Notifications |

### Modified Files (~10)
| File | Change |
|------|--------|
| `config/shopwired.php` | Add `webhook_secret` |
| `routes/api.php` | Add webhook routes |
| `RateLimitServiceProvider.php` | 100 → 300/min |
| `ShopwiredServiceProvider.php` | Register WebhookClient |
| `ShopwiredScheduleServiceProvider.php` | Add health check schedule |
| `Order.php` (Domain) | Make discounts/refunds/adminComments nullable, add null guards |
| `Product.php` (Domain) | Make variations nullable, add null guards |
| `EloquentOrderRepository.php` | Null guards on child sync + `updateStatus()` + `addRefund()` + `deleteByExternalId()` |
| `EloquentProductRepository.php` | Null guard on variations + `updateStock()` |
| `EloquentCustomerRepository.php` | Add `deleteByExternalId()` |
| `OrderModel.php`, `ProductModel.php`, `CustomerModel.php` | Add `shopwired_webhook_at` cast |

---

## Open Items (to verify during implementation)

1. **Exact webhook root structure**: The docs mention `timestamp` and `event` at root level. We'll implement based on docs and adjust if the actual payload differs.

## Resolved Decisions

- **Webhook subentities (confirmed by user)**:
  - Order standard events include: products, discounts, refunds. Excludes: adminComments, customFields
  - Product standard events: no variations included
  - Customer: no child tables, no concern
- **Null-means-skip convention**: All child relation properties made nullable. `null` = don't touch, `[]` = explicitly empty. Extends existing Order two-mode pattern to all entities.
- **Custom event payloads (confirmed by user)**:
  - `order.status_changed` → `{newStatus: {id, name, type, sortOrder}}` (NOT full entity)
  - `order.refund.created` → `{id, orderId, createdAt, amount, description}` (NOT full entity, subject type is `order_refund`)
  - `product.stock_changed` → `{sku, isVariation, newQuantity, orderId?}` (NOT full entity)
  - All other events (including `*.deleted`) → full entity in `data.object`
- **Use case count**: 9 total — 3 sync (standard) + 3 custom + 3 delete
- **Refund routing**: `order.refund.created` routes to the order controller (refunds are children of orders)

---

## Architectural Note: Future Staff Write-Back Pattern

ShopWired is the single source of truth. Our DB is a materialised view. When staff updates are added later, the pattern is:

1. Send **partial** update to ShopWired API (field-level, not full entity)
2. On success, apply the same partial update to our DB (reuses webhook repository methods)
3. Bump `shopwired_webhook_at` to current time (protects against stale webhooks overwriting the staff change)

This is not "multiple writes" — it's a confirmed write + eager cache update. No entity locking needed.

---

## Verification

1. **Unit tests**: Test each use case (staleness rejection, idempotency, happy path), middleware (valid/invalid signature, verification token), enums
2. **Integration tests**: Test full webhook flow — POST to endpoint → DB update → job dispatched
3. **Manual testing**: Register webhook in ShopWired, trigger events, verify DB updates
4. **Linting**: `make lint` (Pint + PHPStan + PHPArkitect + Deptrac)
5. **Test suite**: `make test`

---

## Implementation Order

1. Migration + config (foundation)
2. Enums (shared dependency)
3. Middleware (security first)
4. DTOs (parsing layer)
5. Repository changes (partial update support)
6. Use cases (business logic)
7. Reconciliation jobs (async processing)
8. Controllers + routes (entry points)
9. Webhook health check system (monitoring)
10. Service provider registration (wiring)
11. Tests
