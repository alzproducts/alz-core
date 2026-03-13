# Plan: Centralised Webhook Idempotency via `webhook_events` Table

## Context

Multiple ShopWired webhook types (e.g. `product.updated`, `product.stock_changed`) share a single `shopwired_webhook_at` timestamp column per entity. When two different webhook types fire for the same entity within the same second, the second is incorrectly discarded because ShopWired's timestamp has only second-level granularity and the `>=` comparison treats equal timestamps as "already processed". This caused stock updates to be silently dropped in production (webhook 337035388 discarded after 337035387 for same product).

**Fix**: Replace per-entity `shopwired_webhook_at` columns with a centralised `shopwired.webhook_events` table, using ShopWired's monotonically increasing `webhook_id` for ordering instead of timestamps.

## Architecture

- **Enum**: `App\Application\Shopwired\Enums\WebhookTopic` — string-backed enum of all ShopWired webhook topics
- **Interface**: `App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface`
- **Implementation**: `App\Infrastructure\Shopwired\Services\EloquentWebhookIdempotencyService`
- **Model**: `App\Infrastructure\Shopwired\Models\WebhookEventModel`
- **Consumed by**: Each webhook use case individually (not at routing service level)

## Implementation Steps

### Phase 1: Create New Infrastructure (Additive)

#### Step 1: WebhookTopic Enum

**Create**: `app/Application/Shopwired/Enums/WebhookTopic.php`

```php
enum WebhookTopic: string
{
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductStockChanged = 'product.stock_changed';
    case ProductDeleted = 'product.deleted';
    case OrderCreated = 'order.created';
    case OrderUpdated = 'order.updated';
    case OrderFinalized = 'order.finalized';
    case OrderStatusChanged = 'order.status_changed';
    case OrderRefundCreated = 'order.refund_created';
    case OrderDeleted = 'order.deleted';
    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';
    case CustomerDeleted = 'customer.deleted';
}
```

**Also modify**: `phparkitect.php` — add `'App\Application\Shopwired\Enums'` to the Application naming rule exclusion list (line ~381).

#### Step 2: Migration — `shopwired.webhook_events`

**Create**: `database/migrations/2026_03_15_100000_create_shopwired_webhook_events_table.php`

```sql
shopwired.webhook_events
├── id (uuid PK, gen_random_uuid())
├── subject_id (integer)          -- ShopWired entity external ID
├── topic (string)                -- WebhookTopic->value
├── webhook_id (integer, UNIQUE)  -- ShopWired's globally unique monotonic event ID
├── event_time (timestampTz)      -- for observability only
├── created_at (timestampTz)
├── updated_at (timestampTz)
├── INDEX (subject_id, topic, webhook_id)  -- for isSuperseded() query
```

`webhook_id` is the natural unique key (globally unique per ShopWired webhook). The composite index supports the `isSuperseded()` lookup.

Follow existing migration conventions: idempotent `Schema::create`, `shopwired.` schema prefix.

#### Step 3: Interface

**Create**: `app/Application/Contracts/Shopwired/WebhookIdempotencyServiceInterface.php`

```php
interface WebhookIdempotencyServiceInterface
{
    /**
     * Returns true if a webhook with same or higher ID was already processed
     * for this (subject_id, topic) pair.
     *
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function isSuperseded(IntId $subjectId, WebhookTopic $topic, int $webhookId): bool;

    /**
     * Record a successfully processed webhook event.
     *
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function record(IntId $subjectId, WebhookTopic $topic, int $webhookId, DateTimeImmutable $eventTime): void;
}
```

Two methods (check + record). Recording is done AFTER successful processing — if processing fails and retries, the retry won't be incorrectly rejected as a duplicate.

#### Step 4: Eloquent Model

**Create**: `app/Infrastructure/Shopwired/Models/WebhookEventModel.php`

Standard model: `$table = 'shopwired.webhook_events'`, UUID PK, `$fillable` array.

#### Step 5: Implementation

**Create**: `app/Infrastructure/Shopwired/Services/EloquentWebhookIdempotencyService.php`

**`isSuperseded()`** — SELECT via EloquentGateway:
```sql
SELECT 1 FROM shopwired.webhook_events
WHERE subject_id = ? AND topic = ? AND webhook_id >= ?
LIMIT 1
```
Returns `true` if row found, `false` otherwise. Uses the `(subject_id, topic, webhook_id)` composite index.

**`record()`** — Simple INSERT via EloquentGateway/Model:
```php
WebhookEventModel::create([
    'subject_id' => $subjectId->value,
    'topic' => $topic->value,
    'webhook_id' => $webhookId,
    'event_time' => $eventTime,
]);
```
Standard model creation — no raw SQL needed. The UNIQUE constraint on `webhook_id` prevents exact duplicates; catch `DuplicateRecordException` for idempotent retries.

#### Step 6: Service Provider

**Modify**: `app/Providers/ShopwiredServiceProvider.php`

- Add singleton binding: `WebhookIdempotencyServiceInterface::class => EloquentWebhookIdempotencyService::class`
- Add to `provides()` array

### Phase 2: Rewire Use Cases

#### Step 7: Update all 5 webhook use cases

**Files to modify**:
- `app/Application/Shopwired/UseCases/Webhooks/SyncProductUseCase.php`
- `app/Application/Shopwired/UseCases/Webhooks/UpdateProductStockUseCase.php`
- `app/Application/Shopwired/UseCases/Webhooks/SyncOrderUseCase.php`
- `app/Application/Shopwired/UseCases/Webhooks/UpdateOrderStatusUseCase.php`
- `app/Application/Shopwired/UseCases/Webhooks/SyncCustomerUseCase.php`

Per use case:

a) Add constructor param: `private WebhookIdempotencyServiceInterface $idempotency`

b) Add `WebhookTopic $topic` parameter to `execute()` method signature

c) Replace old idempotency block with check + record pattern:
```php
// BEFORE:
$existing = $this->productRepository->getWebhookTimestamp($productId);
if ($existing !== null && $existing >= $eventTime) { ... return; }
// ... process ...
// (timestamp was saved inside saveFromWebhook or updateWebhookTimestamp)

// AFTER:
if ($this->idempotency->isSuperseded($productId, $topic, $webhookId)) { ... return; }
// ... process ...
$this->idempotency->record($productId, $topic, $webhookId, $eventTime);
```

d) Replace `$this->*Repository->updateWebhookTimestamp()` calls with `$this->idempotency->record()` (in `UpdateProductStockUseCase` and `UpdateOrderStatusUseCase`)

e) Remove `$eventTime` from `saveFromWebhook()` calls and add `$this->idempotency->record()` after them (in `SyncProductUseCase`, `SyncOrderUseCase`, `SyncCustomerUseCase`)

#### Step 8: Update routing services to pass `WebhookTopic` through

**Files to modify**:
- `app/Application/Shopwired/Services/HandleProductWebhookService.php` — convert `$topic` string to `WebhookTopic` enum, pass to use cases
- `app/Application/Shopwired/Services/HandleOrderWebhookService.php` — same
- `app/Application/Shopwired/Services/HandleCustomerWebhookService.php` — same

The `$topic` raw string is already available in each service's `execute()` method. Convert via `WebhookTopic::from($topic)` before passing to use cases. This will also validate the topic string (throws `ValueError` for unknown topics).

### Phase 3: Clean Up Repository Layer

#### Step 9: Remove webhook timestamp methods from interfaces

**Files to modify**:
- `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` — remove `getWebhookTimestamp()`, `updateWebhookTimestamp()`
- `app/Application/Contracts/Shopwired/OrderRepositoryInterface.php` — remove same
- `app/Application/Contracts/Shopwired/CustomerRepositoryInterface.php` — remove same

#### Step 10: Update `saveFromWebhook()` signatures

Remove `DateTimeImmutable $webhookAt` parameter from:
- `ProductRepositoryInterface::saveFromWebhook(Product $product, array $presentEmbeds = []): void`
- `OrderRepositoryInterface::saveFromWebhook(Order $order): void`
- `CustomerRepositoryInterface::saveFromWebhook(Customer $customer, array $presentEmbeds = []): void`

#### Step 11: Update Eloquent repository implementations

**Files to modify**:
- `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`
  - Remove `getWebhookTimestamp()`, `updateWebhookTimestamp()` methods
  - Update `saveFromWebhook()`: remove `$webhookAt` param, remove `'shopwired_webhook_at' => $webhookAt` from extra array
- `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php` — same changes
- `app/Infrastructure/Shopwired/Repositories/EloquentCustomerRepository.php` — same changes

### Phase 4: Drop Old Column

#### Step 12: Remove `shopwired_webhook_at` from Eloquent model casts

**Files to modify**:
- `app/Infrastructure/Shopwired/Models/ProductModel.php` — remove `'shopwired_webhook_at' => 'immutable_datetime'` from `$casts`
- `app/Infrastructure/Shopwired/Models/OrderModel.php` — remove same
- `app/Infrastructure/Shopwired/Models/CustomerModel.php` — remove same

#### Step 13: Migration — drop `shopwired_webhook_at`

**Create**: `database/migrations/2026_03_15_200000_drop_shopwired_webhook_at_from_shopwired_tables.php`

Drop `shopwired_webhook_at` from `shopwired.products`, `shopwired.orders`, `shopwired.customers`.

Separate migration from Step 2 for clean rollback path.

### Phase 5: Tests

#### Step 14: Integration test for `EloquentWebhookIdempotencyService`

**Create**: `tests/Feature/Infrastructure/Shopwired/Services/EloquentWebhookIdempotencyServiceTest.php`

Test cases for `isSuperseded()`:
- No existing row → returns `false` (not superseded, should process)
- Existing row with same `webhook_id` → returns `true` (duplicate)
- Existing row with higher `webhook_id` → returns `true` (out-of-order)
- Existing row with lower `webhook_id` → returns `false` (newer, should process)
- **Different topics, same subject_id → tracked independently** (validates the core bug fix)
- Different subject_id, same topic → tracked independently

Test cases for `record()`:
- First record for `(subject_id, topic)` → inserts row
- Duplicate `webhook_id` → no error (idempotent via unique constraint + DuplicateRecordException catch)
- Verify `event_time` is stored for observability

#### Step 15: Update existing use case tests

**Files to modify**:
- `tests/Unit/Application/Shopwired/UseCases/Webhooks/SyncProductUseCaseTest.php`
- `tests/Feature/Application/Shopwired/UseCases/Webhooks/SyncOrderUseCaseTest.php`

Changes:
- Add `WebhookIdempotencyServiceInterface` mock
- Replace `getWebhookTimestamp` mock expectations → `isSuperseded` mock returning `true`/`false`
- Add `record` mock expectations for the happy path (called after processing)
- Verify `record` is NOT called when `isSuperseded` returns `true`
- Update `saveFromWebhook` expectations (remove `$eventTime` parameter)
- Remove `updateWebhookTimestamp` expectations
- Add `WebhookTopic` enum to `execute()` calls

## Files Changed Summary

| File | Action |
|------|--------|
| `app/Application/Shopwired/Enums/WebhookTopic.php` | Create |
| `phparkitect.php` | Modify (add enum exclusion) |
| `database/migrations/..._create_shopwired_webhook_events_table.php` | Create |
| `database/migrations/..._drop_shopwired_webhook_at_from_shopwired_tables.php` | Create |
| `app/Application/Contracts/Shopwired/WebhookIdempotencyServiceInterface.php` | Create |
| `app/Infrastructure/Shopwired/Models/WebhookEventModel.php` | Create |
| `app/Infrastructure/Shopwired/Services/EloquentWebhookIdempotencyService.php` | Create |
| `app/Providers/ShopwiredServiceProvider.php` | Modify |
| `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` | Modify |
| `app/Application/Contracts/Shopwired/OrderRepositoryInterface.php` | Modify |
| `app/Application/Contracts/Shopwired/CustomerRepositoryInterface.php` | Modify |
| `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` | Modify |
| `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php` | Modify |
| `app/Infrastructure/Shopwired/Repositories/EloquentCustomerRepository.php` | Modify |
| `app/Infrastructure/Shopwired/Models/ProductModel.php` | Modify (remove cast) |
| `app/Infrastructure/Shopwired/Models/OrderModel.php` | Modify (remove cast) |
| `app/Infrastructure/Shopwired/Models/CustomerModel.php` | Modify (remove cast) |
| `app/Application/Shopwired/UseCases/Webhooks/SyncProductUseCase.php` | Modify |
| `app/Application/Shopwired/UseCases/Webhooks/UpdateProductStockUseCase.php` | Modify |
| `app/Application/Shopwired/UseCases/Webhooks/SyncOrderUseCase.php` | Modify |
| `app/Application/Shopwired/UseCases/Webhooks/UpdateOrderStatusUseCase.php` | Modify |
| `app/Application/Shopwired/UseCases/Webhooks/SyncCustomerUseCase.php` | Modify |
| `app/Application/Shopwired/Services/HandleProductWebhookService.php` | Modify |
| `app/Application/Shopwired/Services/HandleOrderWebhookService.php` | Modify |
| `app/Application/Shopwired/Services/HandleCustomerWebhookService.php` | Modify |
| `tests/Feature/Infrastructure/Shopwired/Services/EloquentWebhookIdempotencyServiceTest.php` | Create |
| `tests/Unit/Application/Shopwired/UseCases/Webhooks/SyncProductUseCaseTest.php` | Modify |
| `tests/Feature/Application/Shopwired/UseCases/Webhooks/SyncOrderUseCaseTest.php` | Modify |

## Verification

1. `make test` — all existing + new tests pass
2. `make lint` — PHPStan, PHPArkitect, Deptrac, Pint all pass
3. Manual verification: deploy, trigger two different webhook types for same product within 1 second, confirm both process (not discarded)
