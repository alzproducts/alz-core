# Mixpanel Order Sync Implementation Plan

## Overview

Create a nightly job that syncs orders to Mixpanel, tracking two event types:
- **Checkout Completed** - One per order
- **Product Purchased** - One per product in order

This catches orders missed by the front-end JavaScript SDK.

---

## Prerequisites

### 1. Customer Sync (In Progress)
**Status**: 🚧 Being worked on in separate branch

Before implementing order-to-Mixpanel sync, create a Customer sync feature:
- Sync customers from ShopWired API → PostgreSQL (`shopwired.customers` table)
- Store `isTrade` boolean for `user_is_business_user` lookup
- Pattern: Follow existing `SyncOrdersUseCase` structure

**TODO**: Update this section once Customer sync PR is merged.

### 2. Analytics Salt Configuration
The `order_id_hashed` property is the **primary join key** between front-end and backend events.

**Front-end algorithm** (checkoutPageData.js):
```javascript
hash('sha256', order_reference + analytics_salt)
```

**Backend MUST use identical algorithm:**
- Same salt value used by front-end (from ShopWired config/Twig template)
- Same concatenation order: `reference + salt` (not `salt + reference`)
- Same hash algorithm: SHA-256

**Resolution**: Salt sourced from environment variable `ANALYTICS_SALT`.
- **Fail-fast**: If env var is missing or not a valid non-empty string, job exits immediately with error
- Job MUST NOT proceed without valid salt (would cause 100% duplication)

**Action Required**: ~~User to add `ANALYTICS_SALT` to `.env` (local) and production environment variables.~~ ✅ Done

---

## ⚠️ Pre-PR Consideration: Front-end Deduplication

> **IMPORTANT**: This section must be discussed and acknowledged before merging the PR.

**Current finding**: Front-end does NOT set explicit `$insert_id` for Mixpanel events. It relies on SDK auto-generated UUIDs, meaning:
- Same order tracked twice (page refresh) → different `$insert_id` → no deduplication
- The `order_id_hashed` is only a regular property, not used for deduplication

**Why this isn't blocking implementation**: Our backend uses a pre-export check via `order_id_hashed` to detect existing orders in Mixpanel before importing. This prevents backend-to-frontend duplicates regardless of how the frontend handles `$insert_id`.

**Front-end reference files** (shopwired-theme repo):
- Event names: `/Users/tom/code/IdeaProjects/shopwired-theme/assets/js/services/mixpanelEvents.js`
- Checkout tracking: `/Users/tom/code/IdeaProjects/shopwired-theme/assets/js/entry/checkout/checkoutTracking.js`
- Mixpanel service: `/Users/tom/code/IdeaProjects/shopwired-theme/assets/js/services/mixpanelService.js`
- Checkout data extraction: `/Users/tom/code/IdeaProjects/shopwired-theme/assets/js/utils/data/checkoutPageData.js`

**Outstanding questions** (for future consideration):
- [ ] Confirm whether Mixpanel is currently receiving duplicate events from front-end
- [ ] Decide: Update front-end to use `$insert_id = order_id_hashed`?
- [ ] If yes, coordinate front-end + backend to use same `$insert_id` format for true cross-system deduplication

**Impact on backend implementation**:
- If front-end will use `$insert_id = order_id_hashed`, backend must use same format
- If front-end won't change, backend uses its own deterministic format (backend-to-backend deduplication only)

---

## Key Design Decisions

| Decision | Value |
|----------|-------|
| User Identity | `$user_id = $order->customer->id` (ShopWired customer ID, matches front-end) |
| Deduplication | Pre-export check via `order_id_hashed` (see Deduplication Strategy) |
| is_quote | Always `false` for backend-synced orders |
| user_is_business_user | Lookup from local `customers.is_trade` |
| isPreOrder | `status.name === OrderStatusType::Preorder` |
| Order Filter | All orders (include status properties for filtering) |
| Time Window | `from` to `to - 4 hours` (buffer for Mixpanel ingestion) |
| Source Property | `source: "backend-sync"` |

---

## Deduplication Strategy

### Why Pre-Export Check is Required

**Mixpanel does NOT deduplicate based on custom properties like `order_id_hashed`.**

Mixpanel only deduplicates when ALL FOUR match: `event`, `distinct_id`, `time`, `$insert_id`. Since:
- Front-end uses SDK-generated random `$insert_id` (not deterministic)
- Front-end timestamps differ from backend sync timestamps

→ We **cannot rely on Mixpanel's deduplication**. We must check before importing.

### Algorithm

```
1. Define sync window: [from, to - 4 hours]
   - The 4-hour buffer allows Mixpanel to ingest front-end events

2. Export existing events from Mixpanel:
   - Date range: [from - 24 hours, to] (cushion for edge cases)
   - Event: "Checkout Completed"
   - Extract: Set<order_id_hashed>

3. FAIL if Mixpanel export fails OR returns empty
   - Do not proceed with partial or missing data
   - Sync must be retried when Mixpanel returns valid results

4. Filter orders to import:
   - Only orders where order_id_hashed NOT in existing set

5. Import filtered orders to Mixpanel
```

### Time Buffer Rationale

| Buffer | Purpose |
|--------|---------|
| **4 hours** on `to` boundary | Gives Mixpanel time to ingest front-end events before we check |
| **24 hours** cushion on export `from` | Catches orders placed just before sync window that may have been tracked late |

### Failure Modes

| Failure | Behavior |
|---------|----------|
| Mixpanel Export API fails | **FAIL entire sync** — do not import with incomplete dedup data |
| Mixpanel Export returns empty | **FAIL entire sync** — empty result could mask API issues |
| Mixpanel Import API fails | Retry with backoff (existing behavior) |

### Interface Addition

```php
interface MixpanelClientInterface
{
    /**
     * Export existing Checkout Completed events in date range.
     *
     * @return Set<string> order_id_hashed values
     * @throws ExternalServiceUnavailableException When export fails
     */
    public function getExistingOrderHashes(
        DateTimeImmutable $from,
        DateTimeImmutable $to,
    ): array;
}
```

---

## Architecture

```
Presentation: SyncOrdersToMixpanelJob (nightly 2AM)
    │
    ▼
Application: SyncOrdersToMixpanelUseCase
    │
    ├─ 1. MixpanelClientInterface.getExistingOrderHashes()  ← FAIL if this fails or empty
    │      └─ Returns Set<order_id_hashed> from Mixpanel
    │
    ├─ 2. OrderRepositoryInterface.getOrdersInDateRange()
    │      └─ Orders from [from, to - 4 hours]
    │
    ├─ 3. Filter: Remove orders where hash exists in Mixpanel
    │
    ├─ 4. CustomerRepositoryInterface.getTradeStatusByIds()
    │
    └─ 5. MixpanelClientInterface.importOrders()
           └─ Only missing orders
    │
    ▼
Infrastructure: MixpanelClient + DTOs
    ├─ MixpanelCheckoutCompletedDTO
    ├─ MixpanelProductPurchasedDTO
    └─ Raw Export API integration (for dedup check)
```

---

## Files to Create

### Application Layer

| File | Purpose |
|------|---------|
| `app/Application/Mixpanel/UseCases/SyncOrdersToMixpanelUseCase.php` | Orchestrates order fetch → transform → import |
| `app/Application/Mixpanel/ValueObjects/SyncOrdersToMixpanelResult.php` | Result VO with counts |

### Infrastructure Layer

| File | Purpose |
|------|---------|
| `app/Infrastructure/Mixpanel/DTOs/MixpanelCheckoutCompletedDTO.php` | Transform Order → Checkout event |
| `app/Infrastructure/Mixpanel/DTOs/MixpanelProductPurchasedDTO.php` | Transform OrderProduct → Product event |

### Presentation Layer

| File | Purpose |
|------|---------|
| `app/Presentation/Jobs/SyncOrdersToMixpanelJob.php` | Queued job with retry logic |

---

## Files to Modify

| File | Change |
|------|--------|
| `app/Application/Contracts/Shopwired/OrderRepositoryInterface.php` | Add `getOrdersInDateRange(from, to)` |
| `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php` | Implement date range query |
| `app/Application/Contracts/MixpanelClientInterface.php` | Add `getExistingOrderHashes()` and `importOrders()` |
| `app/Infrastructure/Mixpanel/MixpanelClient.php` | Implement Raw Export API + Import API |
| `routes/console.php` | Add nightly schedule at 2AM |

---

## Event Properties

### Checkout Completed

```php
[
    '$insert_id' => 'CC-{orderIdHashed32}',  // Deduplication
    '$user_id' => (string) $order->customer->id,    // Identity (ShopWired customer ID)
    'time' => $orderPlacedAt->getTimestamp(),
    'order_id_hashed' => hash('sha256', $reference . $analyticsSalt),  // MUST match front-end algorithm!
    'total_inc_vat' => $order->total,
    'sub_total_excl_shipping' => $order->subTotal,
    'vat' => $order->taxValue,               // From Order
    'shipping_total' => $order->shippingTotal,
    'total_excl_vat' => $order->total - $order->taxValue,
    'currency' => 'GBP',
    'payment_method' => $order->paymentMethod->value,
    'source' => 'backend-sync',
    'is_quote' => false,
    'is_pre_order' => $status->name === OrderStatusType::Preorder,
    'user_is_business_user' => $customerTradeMap[$customerId] ?? false,
    // NOTE: customer_id intentionally excluded (front-end removes as PII)
    'order_shipping_country' => $shippingAddress->country,
    'order_billing_country' => $billingAddress->country,
    'has_uk_shipping_address' => $shippingAddress->country === 'United Kingdom',
    'item_count' => count($products),
    'total_quantity' => sum($product->quantity),
    'cart' => [
        ['sku' => ..., 'name' => ..., 'price' => ..., 'quantity' => ..., 'total' => ..., 'position' => 1],
        // ...
    ],
]
```

### Product Purchased (one per product)

```php
[
    '$insert_id' => 'PP-{orderHash16}-{skuHash8}',
    '$user_id' => (string) $order->customer->id,
    'time' => $orderPlacedAt->getTimestamp(),
    'sku' => $product->sku,
    'name' => $product->title,
    'price' => $product->price,
    'quantity' => $product->quantity,
    'total' => $product->total,
    'order_id_hashed' => hash('sha256', $reference . $analyticsSalt),  // MUST match front-end algorithm!
    'currency' => 'GBP',
    'order_payment_method' => $order->paymentMethod->value,
    'order_shipping_country' => $shippingAddress->country,
    'is_quote' => false,
    'user_is_business_user' => $customerTradeMap[$customerId] ?? false,
]
```

---

## Insert ID Format (Deduplication)

| Event | Format | Example | Max Length |
|-------|--------|---------|------------|
| Checkout Completed | `CC-{hash32}` | `CC-a1b2c3...` | 35 chars |
| Product Purchased | `PP-{hash16}-{skuHash8}` | `PP-a1b2c3d4...-x9y8z7w6` | 28 chars |

Mixpanel limit: 36 characters. Both formats are under limit.

---

## Exception Handling

**Key behaviors:**
- Export fails or returns empty → **FAIL immediately** (cannot proceed without dedup data)
- Import fails → Retry with backoff
- Missing customer → Log warning, default `isTrade=false`

---

## Job Configuration

```php
final class SyncOrdersToMixpanelJob implements ShouldQueue
{
    public int $tries = 5;
    public array $backoff = [60, 120, 240, 480, 960];  // Exponential

    public function __construct(
        public readonly DateTimeImmutable $from,
        public readonly DateTimeImmutable $to,
    ) {}
}
```

**Schedule**: Daily at 2:00 AM Europe/London

**Buffer explained**: Orders from the last 4 hours are excluded because:
1. Front-end may have just tracked them
2. Mixpanel needs time to ingest and make them available via Export API
3. These orders will be picked up in the next night's sync

---

## Verification

### Unit Tests
- [ ] `SyncOrdersToMixpanelUseCaseTest` - Mock repository/client, verify event counts
- [ ] `MixpanelCheckoutCompletedDTOTest` - Property mapping, insert_id format
- [ ] `MixpanelProductPurchasedDTOTest` - Property mapping, insert_id uniqueness

### Integration Tests
- [ ] `EloquentOrderRepository::getOrdersInDateRange()` - Date filtering with products
- [ ] `MixpanelClient::importOrders()` - HTTP request format

### Manual Verification
1. Run sync for small date range: `php artisan tinker` → dispatch job
2. Check Mixpanel Live View for events with `source: backend-sync`
3. Verify `$insert_id` prevents duplicates on re-run
4. Confirm event properties match front-end structure

---

## Implementation Order

1. **Prerequisites**
   - [x] ~~Merge `taxValue` branch into Order model~~ ✅ Already exists (`Order::$taxValue`)
   - [ ] Implement Customer sync (separate task/PR) — 🚧 In progress
   - [x] Add `ANALYTICS_SALT` to local `.env` and production env vars ✅

2. **Core Implementation**
   - [ ] Add `OrderRepositoryInterface::getOrdersInDateRange()`
   - [ ] Implement in `EloquentOrderRepository`
   - [ ] Create DTO classes (CheckoutCompleted, ProductPurchased)
   - [ ] Add `MixpanelClientInterface::importOrders()`
   - [ ] Implement in `MixpanelClient`
   - [ ] Create `SyncOrdersToMixpanelUseCase`
   - [ ] Create `SyncOrdersToMixpanelJob`

3. **Testing & Scheduling**
   - [ ] Write unit tests
   - [ ] Add schedule to `routes/console.php`
   - [ ] Manual verification in Mixpanel

---

## Backfill Process

The same deduplication logic applies to both nightly sync and historical backfill. The use case accepts a date range and handles deduplication automatically.

### Running a Backfill

```php
// Backfill command example
$useCase->execute(
    from: new DateTimeImmutable('2024-10-01'),
    to: new DateTimeImmutable('2025-01-01'),
);
```

### Backfill Considerations

| Consideration | Approach |
|---------------|----------|
| **Large date ranges** | Run in weekly batches to avoid timeouts |
| **Mixpanel Export rate limit** | 60 queries/hour, 100k events/query — batch accordingly |
| **Progress tracking** | Log each batch: `Batch 2024-10-01 to 2024-10-07: 45 orders synced, 312 skipped (already in Mixpanel)` |
| **Idempotency** | Safe to re-run — dedup check prevents duplicates |

### Artisan Command

Consider creating a dedicated backfill command with progress output:

```php
php artisan mixpanel:backfill-orders --from=2024-10-01 --to=2025-01-01 --batch-size=7days
```

This would:
1. Split range into weekly batches
2. Run dedup check + import for each batch
3. Output progress and counts
4. Resume from last successful batch on failure
