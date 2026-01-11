# ShopWired Model Architecture Plan

## Overview

Design the complete model system for ShopWired integration, establishing patterns for:
- Domain entities (pure business logic)
- Eloquent models (database persistence)
- Repository interfaces (persistence contracts)
- Sync patterns (webhook + overnight batch)

---

## Architectural Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Entity Strategy | Pure Domain Entities | LLMs nullify mapping overhead; isolated testable business logic |
| Eloquent Location | `Infrastructure/ShopWired/Models/` | Per-integration organization (8 integrations × 30-40 models each) |
| Model Naming | Suffix with `Model` | `OrderModel` (Eloquent) vs `Order` (Domain) - clear distinction |
| External IDs | ShopWired ID in Domain (`id`) + internal UUID in DB | Domain uses ShopWired ID; DB UUID stays in Infrastructure |
| Database Schema | Dedicated `shopwired` PostgreSQL schema | Organizes all ShopWired tables; ~30-40 tables expected |
| Repository Naming | `getByExternalId`, `getByReference` | No internal UUID exposed; Application uses ShopWired ID or reference |
| Sync Strategy | ShopWired is source of truth | Simple, predictable; local DB is read cache with enrichments |
| Mutability | Immutable Domain entities | Reconstruct from source; repository handles upsert |
| Line Items | Separate models with stable identity | `OrderProductModel` with `external_id` for upsert; enables partial fulfillments, returns |
| Domain Structure | Keep Order in ValueObjects/ (immutable) | Works for sync pattern—Order is replaced, never mutated |
| Not Found Handling | Exceptions (never nullable) | Matches existing pattern (`ResourceNotFoundException`) |
| Bulk Operations | Continue on failure, return results | Resilient sync with `SaveManyResult` |
| Query Methods | Minimal for now | Start simple; add query methods as use cases emerge |
| Lifecycle Status | Store in DB, derive via mapper | Enables fast queries; reverse mapper: status_type → OrderLifecycleStatus |
| Addresses/Shipping | JSONB columns | Simple, no joins, matches immutable pattern |
| Repository Exceptions | `DatabaseOperationFailedException` | Distinct from external API errors; no retry on constraint violations |

---

## Domain Order Changes

The existing `Order` value object needs a new `id` property for ShopWired's order ID:

```php
// app/Domain/Catalog/Order/ValueObjects/Order.php
final readonly class Order
{
    public function __construct(
        public int $id,              // ← NEW: ShopWired's order ID
        public int $reference,       // Existing: business reference number
        public float $total,
        public float $subTotal,
        public float $shippingTotal,
        public PaymentMethod $paymentMethod,
        public string $comments,
        public bool $marketing,
        public OrderStatus $status,
        public OrderCustomer $customer,
        public ?OrderShipping $shipping,
        public OrderAddress $billingAddress,
        public OrderAddress $shippingAddress,
        public array $discounts = [],
        public ?array $products = null,
        public ?array $customFields = null,
    ) {
        Assert::greaterThan($id, 0, 'Order ID must be positive');
        Assert::greaterThan($reference, 0, 'Order reference must be positive');
        // ... rest unchanged
    }
}
```

**Impact:**
- `OrderClient` mapper needs to populate `id` from API response
- Existing tests for `Order` need updating
- `getByExternalId(int)` in repository uses this ID

---

## Data Flow Architecture

```
ShopWired API
    ↓
OrderResponse (Infrastructure DTO - app/Infrastructure/ShopWired/Responses/)
    ↓ (Mapper in OrderClient)
Order (Domain Entity - app/Domain/Catalog/Order/ValueObjects/)
    ↓
SyncOrdersUseCase (Application)
    ↓ uses
OrderRepositoryInterface (Application/Contracts/Shopwired/)
    ↓ implemented by
EloquentOrderRepository (Infrastructure/ShopWired/Repositories/)
    ↓ uses
OrderModel (Infrastructure/ShopWired/Models/)
    ↓
PostgreSQL Database
```

---

## Files to Create

### Phase 1: Repository Interface & Supporting Types

| File | Layer | Purpose |
|------|-------|---------|
| `app/Application/Contracts/Shopwired/OrderRepositoryInterface.php` | Application | Database persistence contract |
| `app/Application/Shopwired/ValueObjects/SaveManyResult.php` | Application | Bulk operation result VO |

### Phase 2: Eloquent Models

| File | Layer | Purpose |
|------|-------|---------|
| `app/Infrastructure/ShopWired/Models/OrderModel.php` | Infrastructure | Eloquent model for orders |
| `app/Infrastructure/ShopWired/Models/OrderProductModel.php` | Infrastructure | Eloquent model for line items |
| `app/Infrastructure/ShopWired/Models/OrderDiscountModel.php` | Infrastructure | Eloquent model for discounts |

### Phase 3: Repository Implementation & Mappers

| File | Layer | Purpose |
|------|-------|---------|
| `app/Infrastructure/ShopWired/Repositories/EloquentOrderRepository.php` | Infrastructure | Repository implementation |
| `app/Infrastructure/ShopWired/Mappers/StatusTypeToLifecycleMapper.php` | Infrastructure | Reverse mapper: status_type → OrderLifecycleStatus |

### Phase 4: Database Migration

| File | Purpose |
|------|---------|
| `database/migrations/xxxx_create_shopwired_schema.php` | Create `shopwired` PostgreSQL schema |
| `database/migrations/xxxx_create_shopwired_orders_table.php` | Orders table with `external_id` |
| `database/migrations/xxxx_create_shopwired_order_products_table.php` | Line items table |
| `database/migrations/xxxx_create_shopwired_order_discounts_table.php` | Discounts table |

### Phase 5: Documentation

| File | Purpose |
|------|---------|
| `app/Infrastructure/ShopWired/CLAUDE.md` | Add Model Architecture section |

---

## OrderRepositoryInterface Design

```php
interface OrderRepositoryInterface
{
    /**
     * Persist an order (upsert based on ShopWired ID).
     *
     * @throws DatabaseOperationFailedException On constraint violations or schema errors
     */
    public function save(Order $order): void;

    /**
     * Persist multiple orders, continuing on individual failures.
     *
     * @param list<Order> $orders
     * @return SaveManyResult Results with succeeded/failed counts
     * @throws DatabaseOperationFailedException When DB completely unavailable
     */
    public function saveMany(array $orders): SaveManyResult;

    /**
     * Get order by ShopWired's order ID.
     *
     * @throws ResourceNotFoundException When order not found
     * @throws DatabaseOperationFailedException On query failure
     */
    public function getByExternalId(int $externalId): Order;

    /**
     * Get order by order reference number.
     *
     * @throws ResourceNotFoundException When order not found
     * @throws DatabaseOperationFailedException On query failure
     */
    public function getByReference(int $reference): Order;

    /**
     * Check existence without exception.
     */
    public function existsByExternalId(int $externalId): bool;
}
```

**Note**: No `getById(UUID)` method — internal database UUID stays hidden in Infrastructure layer.

---

## SaveManyResult Value Object

```php
final readonly class SaveManyResult
{
    /**
     * @param list<int> $failedReferences Order references that failed to save
     */
    public function __construct(
        public int $succeeded,
        public int $failed,
        public array $failedReferences = [],
    ) {}

    public function hasFailures(): bool
    {
        return $this->failed > 0;
    }

    public function allSucceeded(): bool
    {
        return $this->failed === 0;
    }
}
```

Location: `app/Application/Shopwired/ValueObjects/SaveManyResult.php`

---

## Database Schema

All ShopWired tables live in a dedicated `shopwired` PostgreSQL schema.

```sql
-- Create dedicated schema for ShopWired data
CREATE SCHEMA IF NOT EXISTS shopwired;

-- Orders table
CREATE TABLE shopwired.orders (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    external_id INTEGER NOT NULL UNIQUE,   -- ShopWired's order ID (Order.id in Domain)
    reference INTEGER NOT NULL UNIQUE,      -- Order reference number

    -- Financials
    total DECIMAL(10, 2) NOT NULL,
    sub_total DECIMAL(10, 2) NOT NULL,
    shipping_total DECIMAL(10, 2) NOT NULL,

    -- Status (raw from ShopWired)
    status_id INTEGER NOT NULL,            -- For API filtering (not in Domain)
    status_name VARCHAR(255) NOT NULL,     -- OrderStatusType enum value
    status_type VARCHAR(50) NOT NULL,      -- Raw status type string

    -- Lifecycle status (derived, for fast queries)
    lifecycle_status VARCHAR(50) NOT NULL, -- OrderLifecycleStatus enum value

    -- Customer (denormalized for query convenience)
    customer JSONB NOT NULL,               -- OrderCustomer as JSONB

    -- Addresses (JSONB - immutable snapshots)
    billing_address JSONB NOT NULL,        -- OrderAddress as JSONB
    shipping_address JSONB NOT NULL,       -- OrderAddress as JSONB

    -- Shipping info (nullable - not all orders have been shipped)
    shipping JSONB,                        -- OrderShipping as JSONB (carrier, tracking, etc.)

    -- Payment
    payment_method VARCHAR(100) NOT NULL,

    -- Flags
    marketing BOOLEAN NOT NULL DEFAULT false,

    -- Metadata
    comments TEXT,
    custom_fields JSONB,

    -- Timestamps
    created_at TIMESTAMP WITH TIME ZONE NOT NULL,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL,
    synced_at TIMESTAMP WITH TIME ZONE NOT NULL  -- When we last synced from ShopWired
);

CREATE INDEX idx_orders_status ON shopwired.orders(lifecycle_status);
CREATE INDEX idx_orders_synced ON shopwired.orders(synced_at);
CREATE INDEX idx_orders_reference ON shopwired.orders(reference);

-- Order products (line items) - stable identity for partial fulfillments/returns
CREATE TABLE shopwired.order_products (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    order_id UUID NOT NULL REFERENCES shopwired.orders(id) ON DELETE CASCADE,
    external_id INTEGER NOT NULL,  -- ShopWired's line item ID (stable across syncs)

    -- Product info
    sku VARCHAR(255),
    name VARCHAR(500) NOT NULL,
    quantity INTEGER NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    total_price DECIMAL(10, 2) NOT NULL,

    -- Timestamps
    created_at TIMESTAMP WITH TIME ZONE NOT NULL,
    updated_at TIMESTAMP WITH TIME ZONE NOT NULL,

    -- Unique constraint for upsert logic
    UNIQUE (order_id, external_id)
);

CREATE INDEX idx_order_products_order ON shopwired.order_products(order_id);
CREATE INDEX idx_order_products_sku ON shopwired.order_products(sku);
```

**Note**: Eloquent models will use `protected $table = 'shopwired.orders';` to reference the schema.

---

## Testing Strategy

Per `tests/TestingStrategy.md`: "Tests exist to catch bugs that static analysis cannot."

| Component | Tests | Approach |
|-----------|-------|----------|
| `SaveManyResult` | 2-3 unit tests | Business logic: `hasFailures()`, `allSucceeded()` |
| `SyncResult` | 2 unit tests | Similar to SaveManyResult |
| `SyncOrdersUseCase` | 2-3 integration tests | Happy path, empty result, partial failure |
| `EloquentOrderRepository` | 2 integration tests | Happy path + error path only |
| `StatusTypeToLifecycleMapper` | 1 unit test | Verify all status types map correctly |
| Domain `Order` | Already tested | 90%+ coverage exists |
| Mappers (Domain) | None | Type system handles structure |

**Test Files to Create:**
- `tests/Unit/Application/Shopwired/ValueObjects/SaveManyResultTest.php`
- `tests/Unit/Application/Shopwired/ValueObjects/SyncResultTest.php`
- `tests/Feature/Application/Shopwired/UseCases/SyncOrdersUseCaseTest.php`
- `tests/Feature/Infrastructure/ShopWired/Repositories/EloquentOrderRepositoryTest.php`
- `tests/Unit/Infrastructure/ShopWired/Mappers/StatusTypeToLifecycleMapperTest.php`

---

## Verification

1. **Migration**: `php artisan migrate` runs successfully
2. **Tests**: `make test` passes (including new repository tests)
3. **Lint & Static Analysis**: `make lint` passes
4. **Manual**: Save/retrieve an Order via tinker to verify roundtrip

---

## Sync Use Case (Included in Implementation)

### SyncOrdersUseCase

```php
// app/Application/Shopwired/UseCases/SyncOrdersUseCase.php
final readonly class SyncOrdersUseCase
{
    public function __construct(
        private OrderClientInterface $orderClient,
        private OrderRepositoryInterface $orderRepo,
        private LoggerInterface $logger,
    ) {}

    public function execute(DateTimeImmutable $from, DateTimeImmutable $to): SyncResult
    {
        $orders = $this->orderClient->listOrdersInRangeWithDetails($from, $to);

        if ($orders === []) {
            return new SyncResult(fetched: 0, saved: 0, failed: 0);
        }

        $saveResult = $this->orderRepo->saveMany($orders);

        return new SyncResult(
            fetched: count($orders),
            saved: $saveResult->succeeded,
            failed: $saveResult->failed,
            failedReferences: $saveResult->failedReferences,
        );
    }
}
```

### SyncShopwiredOrdersJob (Hourly Scheduled)

**Location**: `app/Presentation/Jobs/SyncShopwiredOrdersJob.php` (Presentation layer - entry point)

```php
// app/Presentation/Jobs/SyncShopwiredOrdersJob.php
final class SyncShopwiredOrdersJob implements ShouldQueue
{
    public int $tries = 3;
    public int $backoff = 60;

    public function __construct(
        private readonly DateTimeImmutable $from,
        private readonly DateTimeImmutable $to,
    ) {}

    public function handle(SyncOrdersUseCase $useCase): void
    {
        $useCase->execute($this->from, $this->to);
    }

    public static function hourly(): self
    {
        $to = new DateTimeImmutable('now');
        $from = $to->modify('-2 hours');  // 2hr overlap for safety
        return new self($from, $to);
    }
}
```

### Schedule (routes/console.php)

```php
Schedule::job(SyncShopwiredOrdersJob::hourly())
    ->hourly()
    ->withoutOverlapping()
    ->onOneServer();
```

### Files to Create

| File | Purpose |
|------|---------|
| `app/Application/Shopwired/UseCases/SyncOrdersUseCase.php` | Orchestrates API → DB sync |
| `app/Application/Shopwired/ValueObjects/SyncResult.php` | Sync operation result |
| `app/Presentation/Jobs/SyncShopwiredOrdersJob.php` | Scheduled hourly job (Presentation layer) |

---

## Future Extensions (Not in this implementation)

- Query object pattern (`OrderQueryCriteria`) when listing needs arise
- Webhook sync (`SyncOrderFromWebhookUseCase`) - different pattern
- Event dispatching on order sync
- Customer, Product repository patterns (follow same architecture)
