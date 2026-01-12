# Plan: Order Domain Improvements (Issue #108 Follow-up)

## Scope

This plan covers three categories of changes to the ShopWired Order persistence:

1. **Bug Fix**: `status_id` is always null
2. **Schema Fix**: `shipping_cost` should be NOT NULL DEFAULT 0.00
3. **Completeness**: Add missing fields from `OrderResponse` API

**Parked for later discussion:**
- Table splitting (addresses, customer, etc.)
- Name field parsing (firstName/lastName)

---

## Phase 1: Fix status_id Bug (Critical)

**Root cause**: `OrderStatusResponse.id` exists but `OrderStatus` domain object doesn't include it.

### Files to modify:

1. **Domain: `app/Domain/Catalog/Order/ValueObjects/OrderStatus.php`**
   - Add `public int $id` property
   - Add `public int $sortOrder` property (while we're here)

2. **Infrastructure: `app/Infrastructure/Shopwired/Responses/OrderStatusResponse.php`**
   - Update `toDomain()` to pass `id` and `sortOrder`

3. **Infrastructure: `app/Infrastructure/Shopwired/Mappers/OrderModelMapper.php`**
   - Line 115: Change `'status_id' => null` to `'status_id' => $order->status->id`
   - Add `'status_sort_order' => $order->status->sortOrder`
   - Update `buildStatus()` to read `status_id` and `status_sort_order` from model

4. **Database: New migration**
   - Add `status_sort_order` column (integer, nullable)

---

## Phase 2: shipping_cost NOT NULL

### Files to modify:

1. **Database: New migration**
   ```php
   public function up(): void
   {
       // Safety: Fill NULLs before altering (for future-proofing if data exists)
       DB::statement('UPDATE shopwired.orders SET shipping_cost = 0 WHERE shipping_cost IS NULL');

       Schema::table('shopwired.orders', function (Blueprint $table) {
           $table->decimal('shipping_cost', 14, 6)->default(0)->nullable(false)->change();
       });
   }
   ```

2. **Infrastructure: `app/Infrastructure/Shopwired/Mappers/OrderModelMapper.php`**
   - Line 148: Change `'shipping_cost' => $order->shipping?->value` to `'shipping_cost' => $order->shipping?->value ?? 0.0`

3. **Infrastructure: `app/Infrastructure/Shopwired/Models/OrderModel.php`**
   - Update `@property` docblock for `shipping_cost` (remove nullable)

---

## Phase 3: Add Missing Scalar Fields

Fields from `OrderResponse` not currently in Domain `Order`:

### Scalar Fields to Add:
| API Field | Domain Property | DB Column | Type |
|-----------|-----------------|-----------|------|
| `archived` | `isArchived` | `is_archived` | bool |
| `anonymized` | `isAnonymized` | `is_anonymized` | bool |
| `originalShippingTotal` | `originalShippingTotal` | `original_shipping_total` | decimal |
| `trackingUrl` | `trackingUrl` | `tracking_url` | string? |
| `invoiceUrl` | `invoiceUrl` | `invoice_url` | string? |
| `transactionId` | `transactionId` | `transaction_id` | string? |
| `deliveryDate` | `deliveryDate` | `delivery_date` | date? |
| `packageWeight` | `packageWeight` | `package_weight` | string? |
| `lineItemVatCalculation` | `lineItemVatCalculation` | `line_item_vat_calculation` | bool |
| `shipping.id` | `shipping.id` | `shipping_id` | int? |
| `billingAddress.countryId` | `billingAddress.countryId` | `billing_country_id` | int |
| `shippingAddress.countryId` | `shippingAddress.countryId` | `delivery_country_id` | int |
| `tax.value` | `taxValue` | `tax_value` | decimal? |

### Pre-Order Handling:

**Order-level `preOrder` (from API):**
- Already in `OrderResponse.preOrder` - keep it there
- Do NOT pass to Domain `Order` - ShopWired's logic is different from ours
- Captured for reference but unused

**Product-level `isPreorder` (NEW):**
- Add `isPreorder: bool` to `OrderProduct` domain object
- Add `is_preorder: bool` column to `shopwired.order_products`
- Derive during domain creation in `OrderProductResponse.toDomain()`:
  ```php
  isPreorder: str_contains(strtolower($this->comments), 'preorder')
  ```
- Future-safe: If ShopWired API adds `preorder` on line items, we can use that instead/additionally

**Order-level `PreOrderStatus` enum (derived from products):**
```php
enum PreOrderStatus: string {
    case None = 'none';       // No products have isPreorder=true
    case Partial = 'partial'; // Some (but not all) products have isPreorder=true
    case Full = 'full';       // ALL products have isPreorder=true
}
```

**Derivation logic** (in mapper, during sync):
```php
$preorderCount = count(array_filter($products, fn(OrderProduct $p): bool => $p->isPreorder));
$totalCount = count($products);

return match(true) {
    $totalCount === 0 => PreOrderStatus::None,        // Edge case: empty order
    $preorderCount === 0 => PreOrderStatus::None,
    $preorderCount === $totalCount => PreOrderStatus::Full,
    default => PreOrderStatus::Partial,
};
```

**DB column on orders**: `pre_order_status VARCHAR(10)` (stored, queryable)

### Fields NOT Adding (per user):
- ~~partialPaymentTotal~~ - Not needed
- ~~referrerId~~ - Not needed
- ~~customerSource~~ - Not needed
- ~~earnedRewardPoints~~ - Not needed
- ~~taxType~~ - Not needed (only taxValue)

### Files to modify:

1. **Domain: `app/Domain/Catalog/Order/ValueObjects/Order.php`**
   - Add all new properties to constructor
   - Add assertions where needed

2. **Domain: `app/Domain/Catalog/Order/ValueObjects/OrderAddress.php`**
   - Add `public int $countryId` property

3. **Domain: `app/Domain/Catalog/Order/ValueObjects/OrderShipping.php`**
   - Add `public ?int $id` property

4. **Infrastructure: `app/Infrastructure/Shopwired/Responses/OrderResponse.php`**
   - Update `toDomain()` to pass new fields

5. **Infrastructure: `app/Infrastructure/Shopwired/Responses/OrderAddressResponse.php`**
   - Update `toDomain()` to pass `countryId`

6. **Infrastructure: `app/Infrastructure/Shopwired/Responses/OrderShippingResponse.php`**
   - Update `toDomain()` to pass `id`

7. **Infrastructure: `app/Infrastructure/Shopwired/Mappers/OrderModelMapper.php`**
   - Add all new fields to `toModelAttributes()`
   - Update `toDomain()` to read new fields

8. **Infrastructure: `app/Infrastructure/Shopwired/Models/OrderModel.php`**
   - Add `@property` docblocks for new columns

9. **Database: New migration**
   - Add all new columns

---

## Phase 4: New Child Tables (Refunds + Admin Comments)

**Important**: All child tables include `order_external_id` (ShopWired's order ID) for easier debugging/lookups, in addition to `order_id` (internal UUID FK).

### 4a: Refunds Table (1:many)

**New table: `shopwired.order_refunds`**
| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid | PK |
| `order_id` | uuid | FK to orders.id |
| `order_external_id` | int | ShopWired order ID (denormalized for queries) |
| `external_id` | int | ShopWired refund ID |
| `created_at_shopwired` | timestamptz | From API `created` field |
| `name` | varchar | Refund description |
| `value` | decimal(14,6) | Refund amount |
| `created_at` | timestamptz | Laravel |
| `updated_at` | timestamptz | Laravel |

**Unique constraint**: `(order_external_id, external_id)` - same pattern as order_products

**Files to create:**
1. **Domain: `app/Domain/Catalog/Order/ValueObjects/OrderRefund.php`**
2. **Infrastructure: `app/Infrastructure/Shopwired/Models/OrderRefundModel.php`**
3. **Database: Migration for `shopwired.order_refunds`**

**Files to modify:**
1. **Domain: `Order.php`**: Add `public array $refunds = []`
2. **Infrastructure: `OrderResponse.php`**: Map refunds in `toDomain()`
3. **Infrastructure: `OrderRefundResponse.php`**: Add `toDomain()` method
4. **Infrastructure: `OrderModel.php`**: Add `refunds()` relationship
5. **Infrastructure: `OrderModelMapper.php`**: Handle refunds in mapping
6. **Infrastructure: `EloquentOrderRepository.php`**: Sync refunds like products/discounts

### 4b: Admin Comments Table (1:many)

**New table: `shopwired.order_admin_comments`**
| Column | Type | Notes |
|--------|------|-------|
| `id` | uuid | PK |
| `order_id` | uuid | FK to orders.id |
| `order_external_id` | int | ShopWired order ID (denormalized) |
| `external_id` | int | ShopWired comment ID |
| `created_at_shopwired` | timestamptz | From API `created` field |
| `content` | text | Comment text |
| `status_id` | int? | Associated status ID |
| `created_at` | timestamptz | Laravel |
| `updated_at` | timestamptz | Laravel |

**Unique constraint**: `(order_external_id, external_id)`

**Files to create:**
1. **Domain: `app/Domain/Catalog/Order/ValueObjects/OrderAdminComment.php`**
2. **Infrastructure: `app/Infrastructure/Shopwired/Models/OrderAdminCommentModel.php`**
3. **Database: Migration for `shopwired.order_admin_comments`**

**Files to modify:**
1. **Domain: `Order.php`**: Add `public array $adminComments = []`
2. **Infrastructure: `OrderResponse.php`**: Map adminComments in `toDomain()`
3. **Infrastructure: `OrderAdminCommentResponse.php`**: Add `toDomain()` method
4. **Infrastructure: `OrderModel.php`**: Add `adminComments()` relationship
5. **Infrastructure: `OrderModelMapper.php`**: Handle adminComments in mapping
6. **Infrastructure: `EloquentOrderRepository.php`**: Sync adminComments

---

## Deferred (not in this plan)

| API Array | Reason |
|-----------|--------|
| `fees` | No current business use |
| `partialPayments` | No current business use |
| `fileArchives` | No current business use |

---

## Critical Files Summary

**Domain (modify):**
```
app/Domain/Catalog/Order/ValueObjects/Order.php
app/Domain/Catalog/Order/ValueObjects/OrderProduct.php
app/Domain/Catalog/Order/ValueObjects/OrderStatus.php
app/Domain/Catalog/Order/ValueObjects/OrderAddress.php
app/Domain/Catalog/Order/ValueObjects/OrderShipping.php
```

**Domain (create):**
```
app/Domain/Catalog/Order/ValueObjects/OrderRefund.php
app/Domain/Catalog/Order/ValueObjects/OrderAdminComment.php
app/Domain/Catalog/Order/Enums/PreOrderStatus.php
```

**Infrastructure Responses (modify):**
```
app/Infrastructure/Shopwired/Responses/OrderResponse.php
app/Infrastructure/Shopwired/Responses/OrderProductResponse.php
app/Infrastructure/Shopwired/Responses/OrderStatusResponse.php
app/Infrastructure/Shopwired/Responses/OrderAddressResponse.php
app/Infrastructure/Shopwired/Responses/OrderShippingResponse.php
app/Infrastructure/Shopwired/Responses/OrderRefundResponse.php
app/Infrastructure/Shopwired/Responses/OrderAdminCommentResponse.php
```

**Infrastructure Models/Mappers (modify):**
```
app/Infrastructure/Shopwired/Mappers/OrderModelMapper.php
app/Infrastructure/Shopwired/Models/OrderModel.php
app/Infrastructure/Shopwired/Models/OrderProductModel.php
app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php
```

**Infrastructure Models (create):**
```
app/Infrastructure/Shopwired/Models/OrderRefundModel.php
app/Infrastructure/Shopwired/Models/OrderAdminCommentModel.php
```

**Database:**
```
database/migrations/2026_01_13_XXXXXX_add_missing_order_fields.php
database/migrations/2026_01_13_XXXXXX_add_is_preorder_to_order_products.php
database/migrations/2026_01_13_XXXXXX_create_order_refunds_table.php
database/migrations/2026_01_13_XXXXXX_create_order_admin_comments_table.php
```

---

## Tests to Update

Adding required constructor parameters will break existing test fixtures:
- `tests/Unit/Domain/Catalog/Order/ValueObjects/OrderStatusTest.php` - add id, sortOrder
- `tests/Unit/Domain/Catalog/Order/ValueObjects/OrderAddressTest.php` - add countryId
- `tests/Unit/Domain/Catalog/Order/ValueObjects/OrderTest.php` - update nested objects
- `tests/Unit/Application/Shopwired/UseCases/SyncOrdersUseCaseTest.php` - update fixtures

---

## Verification

1. **Lint check**: `make lint`
2. **Test suite**: `make test`
3. **Migration**: `php artisan migrate`
4. **Manual verification**:
   ```bash
   php artisan tinker
   # Re-sync a few orders and verify status_id is populated
   ```
5. **DB verification**:
   ```sql
   SELECT external_id, status_id, status_name FROM shopwired.orders LIMIT 5;
   -- Verify status_id is no longer null
   ```

---

## Execution Order

1. **Migrations**: Create all migrations:
   - Orders table alterations (new columns)
   - Refunds table creation
   - Admin comments table creation

2. **Domain layer**:
   - Create `PreOrderStatus` enum
   - Create `OrderRefund` value object
   - Create `OrderAdminComment` value object
   - Update `OrderStatus` (add id, sortOrder)
   - Update `OrderAddress` (add countryId)
   - Update `OrderShipping` (add id)
   - Update `Order` (add all new fields, refunds, adminComments, preOrderStatus)

3. **Response DTOs**: Update all `toDomain()` methods

4. **Infrastructure Models**:
   - Create `OrderRefundModel`
   - Create `OrderAdminCommentModel`
   - Update `OrderModel` (properties, casts, relationships)

5. **Mappers**: Update `OrderModelMapper`:
   - Add PreOrderStatus derivation logic
   - Map all new fields both directions

6. **Repository**: Update `EloquentOrderRepository`:
   - Sync refunds (like products/discounts)
   - Sync adminComments

7. **Run migrations**: `php artisan migrate`
8. **Re-sync orders**: Trigger sync to populate new fields
9. **Verify**: `make lint && make test`
