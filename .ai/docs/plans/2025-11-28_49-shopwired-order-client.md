# OrderClient Implementation Plan

> **GitHub Issue:** [#49 - feat: Add ShopWired OrderClient](https://github.com/alzproducts/alz-core/issues/49)

## Overview

Implement ShopWired Orders API client (`/orders`, `/orders/search`, `/orders/count`, `/orders/{id}`) following existing patterns with **smoke-test-first validation** against real API data.

## Key Architectural Decisions

### 1. Bounded Context: Catalog Order Only
This is `Domain\Catalog\Order` - the ShopWired-specific view of an order. It is NOT a "God Object" containing data from all systems. Future bounded contexts (Fulfillment/Linnworks, Accounting/QuickBooks) will have their own domain objects linked by order reference.

### 2. Summary/Detail Pattern (Data Size Optimization)
Orders can be massive (50+ custom fields per product × 10 products). Two Infrastructure DTOs map to ONE Domain object:

- **`OrderSummary` DTO** → Used by list endpoints → `Order` with nullable detail fields
- **`OrderDetail` DTO** → Used by getById → `Order` with all fields populated

The caller always knows which they're requesting. Nullable fields (`?array $products`) distinguish "not fetched" from "empty".

### 3. No `listAllOrders()` Method
With 10,000s of orders, fetching all is impractical. Only date-range queries supported.

### 4. OrderProduct vs Catalog Product
`OrderProduct` (embedded in Order) is a **snapshot of product data at time of purchase** - different from catalog Product. Contains order-specific pricing, quantity, VAT applied, etc. No need to build ProductClient first.

### 5. Enum Strategy

**Infrastructure enums** (ShopWired-specific):
- `OrderStatusId` - int-backed, for status filter queries (values discovered during smoke test)
- `PaymentMethodRaw` - string-backed, raw API values ('Admin Order', 'PayPal', 'Opayo Hosted', etc.)

**Domain enums** (business categories):
- `OrderStatusType` - string-backed ('paid', 'unpaid', 'shipped', 'cancelled', 'custom')
- `CustomerType` - string-backed ('guest', 'registered')
- `PaymentMethod` - string-backed (Admin, PayPal, Credit, Card)
  - Maps from Infra: Opayo*/Sagepay* → Card, Admin Order → Admin

**Strict enum parsing** - Use `::from()` not `::tryFrom()`. Unknown values throw `ValueError`, forcing explicit handling.

**Payment method mapping in Infrastructure DTO:**
```php
public function toDomainPaymentMethod(): PaymentMethod
{
    return match($this->paymentMethod) {
        'Admin Order' => PaymentMethod::Admin,
        'PayPal' => PaymentMethod::PayPal,
        'Credit' => PaymentMethod::Credit,
        'Offline' => PaymentMethod::Card, // or separate?
        default => str_contains($this->paymentMethod, 'Opayo')
               || str_contains($this->paymentMethod, 'Sagepay')
            ? PaymentMethod::Card
            : throw new \ValueError("Unknown payment method: {$this->paymentMethod}"),
    };
}
```

---

## Implementation Sequence

### Phase 1: Infrastructure DTOs (Smoke-Test-First)

Create DTOs in dependency order, starting defensive (nullable with defaults):

**Order of creation (leaf DTOs first, then composites):**
1. `OrderTax.php` - 2 fields
2. `OrderFee.php` - 3 fields (name, value, type)
3. `OrderDiscount.php` - 6 fields (name, value, type, code, voucherId, offerId)
4. `OrderRefund.php` - 4 fields (id, created, name, value)
5. `OrderShipping.php` - 4 fields (id, name, value, vatRate)
6. `OrderStatus.php` - 4 fields (id, name, type, sortOrder)
7. `OrderPartialPayment.php` - 3 fields (type, value, data)
8. `OrderFileArchive.php` - 2 fields (title, url)
9. `OrderCustomer.php` - **NO domain conversion** (id, type, dateOfBirth, deviceInfo)
10. `OrderAdminComment.php` - **NO domain conversion** (id, created, content, status_id)
11. `OrderAddress.php` - 13 fields (incl. state, countryId)
12. `OrderProduct.php` - 21+ fields (incl. extras, choices, variation, bundleProducts, customFields)
13. `Order.php` - Root object embedding all above

### Phase 2: Smoke Test via Tinker

Use Laravel Tinker for simple smoke testing (no dedicated command needed):

```php
// In Tinker - test parsing orders within a date range
$client = app(\App\Infrastructure\Shopwired\Clients\OrderClient::class);
$from = strtotime('-30 days');
$to = time();
$orders = $client->listOrdersInRange($from, $to); // Will throw on parse failures
count($orders); // Verify count
```

**Iterate until 100% parse rate** across:
- Paid, unpaid, cancelled, shipped orders
- Orders with/without discounts, refunds, tracking
- Trade (B2B) and retail (B2C) orders
- Anonymized orders (GDPR)

### Phase 3: Domain Value Objects (Minimal - YAGNI)

Create ONLY what's needed now. Infrastructure DTOs capture everything; Domain gets business essentials.

**Order (Domain):**
- `reference`, `total`, `subTotal`, `shippingTotal`
- `paymentMethod` → `PaymentMethod` enum (Admin, PayPal, Credit, Card)
- `comments`, `marketing`
- `customFields`, `customProductFields` (arrays)
- `status` → `OrderStatus` VO with `OrderStatusType` enum
- `customer` → `OrderCustomer` VO **with ID**, `CustomerType` enum
- `shipping` → `OrderShipping` VO **without ID**
- `discounts[]` → `OrderDiscount` VO **with IDs** (needed for Mixpanel)
- `billingAddress`, `shippingAddress` → `OrderAddress` VO
- `products[]` (nullable - null for summary, populated for detail)

**OrderProduct (Domain):**
- `id` ✅ (included - needed for tracking)
- `title`, `sku`
- `price`, `priceVat`, `total`, `totalVat`, `originalPrice`, `costPrice`
- `quantity`, `vatRate`
- `comments`
- `variation[]`, `customFields[]` (arrays)

**NOT in Domain (stay in Infrastructure DTO only):**
- Order: tax, fees, refunds, partialPayments, fileArchives, adminComments, trackingUrl, invoiceUrl, transactionId, etc.
- OrderProduct: id (itemId), gtin, mpn, weight, extras, choices, bundleProducts, hsCode, etc.

**Add to Domain later as business needs arise.**

### Phase 4: OrderQueryParams

Create `app/Infrastructure/Shopwired/OrderQueryParams.php`:

```php
final readonly class OrderQueryParams implements PaginatableQueryParams
{
    public function __construct(
        private ShopwiredQueryParams $baseParams = new ShopwiredQueryParams(),
        public ?int $from = null,      // Unix timestamp
        public ?int $to = null,        // Unix timestamp
        public ?int $status = null,    // Status ID filter
        public ?bool $archived = null, // Archived filter
    ) {}

    // withFrom(), withTo(), withStatus(), withArchived(), withBaseParams()
    // toArray() merges base params with order-specific filters
}
```

### Phase 5: OrderClientInterface & OrderClient

**Interface** (`app/Application/Contracts/Shopwired/OrderClientInterface.php`):

```php
interface OrderClientInterface
{
    /**
     * List orders within a date range (paginated fetch) - SUMMARY fields only.
     *
     * @warning ShopWired has strict rate limits. For large date ranges,
     * consider breaking into smaller chunks (e.g., weekly) to avoid 429 errors.
     *
     * @return list<Order> Orders with nullable detail fields (products=null, etc.)
     */
    public function listOrdersInRange(int $from, int $to): array;

    /**
     * List orders within a date range with FULL details including products.
     *
     * Use for syncs requiring complete order data (e.g., Mixpanel daily sync).
     * Heavier payload but avoids N+1 getOrderById calls.
     *
     * @return list<Order> Orders with ALL fields populated
     */
    public function listOrdersInRangeWithDetails(int $from, int $to): array;

    /** @return list<Order> Single page, summary fields only */
    public function listOrders(): array;

    /**
     * Search orders by keyword (reference, name, etc.).
     *
     * @warning API search may not be exact match. Callers MUST verify
     * returned orders match expected criteria before use.
     *
     * @return list<Order> Matching orders with summary fields
     */
    public function searchOrders(string $keyword): array;

    /** @return Order with ALL fields populated */
    public function getOrderById(int $id): Order;

    public function getOrderCount(): int;

    /** @param OrderStatusId $status Infrastructure enum for type-safe status queries */
    public function getOrderCountByStatus(OrderStatusId $status): int;
}
```

**Implementation** (`app/Infrastructure/Shopwired/Clients/OrderClient.php`):
- Uses `ShopwiredResponseParserTrait`
- Pagination via `ShopwiredPaginator::fetchAll()` for `listOrdersInRange()`
- Two field/embed constants: `SUMMARY_FIELDS`/`SUMMARY_EMBEDS` and `DETAIL_FIELDS`/`DETAIL_EMBEDS`
- Both use same `Order` DTO, but summary leaves detail fields as null

### Phase 6: Unit Tests (After Smoke Tests Pass)

Tests with realistic fixtures derived from actual API responses:
- `OrderTest.php`, `OrderProductTest.php`, `OrderAddressTest.php` (DTOs)
- `OrderClientTest.php` (mocked transport)
- `OrderQueryParamsTest.php`

---

## File Structure

```
app/
├── Application/Contracts/Shopwired/
│   └── OrderClientInterface.php
├── Domain/Catalog/Order/
│   ├── Order.php
│   ├── OrderStatus.php
│   ├── OrderStatusType.php          # Domain enum
│   ├── OrderAddress.php
│   ├── OrderShipping.php
│   ├── OrderProduct.php
│   ├── OrderDiscount.php            # Includes IDs (for Mixpanel)
│   ├── OrderCustomer.php            # Includes ID
│   ├── CustomerType.php             # Domain enum (guest/registered)
│   └── PaymentMethod.php            # Domain enum (Admin/PayPal/Credit/Card)
├── Infrastructure/Shopwired/
│   ├── OrderQueryParams.php
│   ├── Enums/
│   │   └── OrderStatusId.php        # Infrastructure enum (int-backed)
│   ├── Clients/
│   │   └── OrderClient.php
│   └── Responses/
│       ├── Order.php               # → Domain
│       ├── OrderStatus.php         # → Domain
│       ├── OrderAddress.php        # → Domain
│       ├── OrderShipping.php       # → Domain
│       ├── OrderProduct.php        # → Domain
│       ├── OrderDiscount.php       # → Domain
│       ├── OrderCustomer.php       # → Domain (includes ID)
│       ├── OrderTax.php            # NO domain (infra only)
│       ├── OrderRefund.php         # NO domain (infra only)
│       ├── OrderFee.php            # NO domain (infra only)
│       ├── OrderPartialPayment.php # NO domain (infra only)
│       ├── OrderFileArchive.php    # NO domain (infra only)
│       └── OrderAdminComment.php   # NO domain (infra only)

tests/Unit/
├── Domain/Catalog/Order/
│   ├── OrderTest.php
│   ├── OrderProductTest.php
│   └── EnumTests.php               # OrderStatusType, CustomerType, PaymentMethod
└── Infrastructure/Shopwired/
    ├── OrderQueryParamsTest.php
    ├── Clients/OrderClientTest.php
    └── Responses/OrderTest.php     # DTO parsing + toDomain()
```

---

## Order API Fields Reference (merged from docs + old-server)

### Order (Root)
| Field | Type | Domain? | Notes |
|-------|------|---------|-------|
| id | int | No | |
| reference | int | Yes | |
| created | string | No | |
| archived | bool | Yes | |
| anonymized | bool | Yes | |
| trackingUrl | ?string | Yes | Customer comms |
| paymentMethod | string | Yes | |
| total | float | Yes | |
| subTotal | float | Yes | |
| shippingTotal | float | Yes | |
| originalShippingTotal | float | Yes | |
| partialPaymentTotal | float | Yes | |
| totalWeight | string | Yes | |
| weightUnit | ?string | Yes | |
| marketing | bool | Yes | |
| comments | string | Yes | |
| invoiceUrl | string | Yes | Accounting |
| deliveryDate | ?string | Yes | |
| transactionId | ?string | No | Payment gateway |
| earnedRewardPoints | float | Yes | |
| preOrder | bool | Yes | |
| referrerId | ?int | No | Internal tracking |
| lineItemVatCalculation | bool | Yes | |
| packageWeight | ?string | Yes | |
| customerSource | ?string | Yes | |

### OrderStatus
| Field | Type | Domain? |
|-------|------|---------|
| id | int | No |
| name | string | Yes |
| type | string | Yes | enum: paid/unpaid/cancelled/shipped/custom |
| sortOrder | int | No |

### OrderAddress (billing/shipping)
| Field | Type | Domain? | Notes |
|-------|------|---------|-------|
| name | string | Yes | |
| emailAddress | string | Yes | |
| telephone | ?string | Yes | |
| companyName | ?string | Yes | |
| addressLine1 | string | Yes | |
| addressLine2 | ?string | Yes | |
| addressLine3 | ?string | Yes | |
| city | string | Yes | |
| province | ?string | Yes | |
| state | ?string | Yes | Separate from province |
| postcode | string | Yes | |
| country | string | Yes | |
| countryId | int | No | Excluded per decision |

### OrderTax
| Field | Type | Domain? |
|-------|------|---------|
| type | string | Yes |
| value | float | Yes |

### OrderShipping (array - use shipping[0])
| Field | Type | Domain? |
|-------|------|---------|
| id | int | No |
| name | string | Yes |
| value | float | Yes |
| vatRate | float | Yes |

### OrderCustomer
| Field | Type | Domain? | Notes |
|-------|------|---------|-------|
| id | int | Yes | Included for tracking |
| type | string | Yes | 'guest' or 'registered' → CustomerType enum |
| dateOfBirth | ?string | No | |
| deviceInfo | ?object | No | |

### OrderProduct
| Field | Type | Domain? | Notes |
|-------|------|---------|-------|
| id | int | Yes | Included for tracking |
| itemId | int | No | |
| title | string | Yes | |
| sku | string | Yes | |
| gtin | ?string | No | |
| mpn | ?string | No | |
| price | float | Yes | |
| priceVat | float | Yes | |
| total | float | Yes | |
| totalVat | float | Yes | |
| originalPrice | float | Yes | |
| costPrice | float | Yes | |
| quantity | int | Yes | |
| vatRate | float | Yes | |
| weight | float | No | |
| rewardPointsEarned | float | No | |
| comments | ?string | Yes | |
| giftVoucher | bool | No | |
| warehouseNotes | ?string | No | |
| preOrder | bool | No | |
| hsCode | ?string | No | |
| extras | array | No | |
| choices | array | No | |
| variation | array | Yes | [{name, value}] |
| bundleProducts | array | No | |
| customFields | array | Yes | [{name, value}] |

### OrderDiscount
| Field | Type | Domain? |
|-------|------|---------|
| name | string | Yes |
| value | float | Yes |
| type | string | Yes |
| code | ?string | Yes |
| voucherId | ?int | Yes | Included for Mixpanel |
| offerId | ?int | Yes | Included for Mixpanel |

### OrderRefund (infra only)
| Field | Type |
|-------|------|
| id | int |
| created | string |
| name | string |
| value | float |

### OrderFee (infra only)
| Field | Type |
|-------|------|
| name | string |
| value | float |
| type | string |

### OrderPartialPayment (infra only)
| Field | Type |
|-------|------|
| type | string |
| value | float |
| data | ?string |

### OrderAdminComment (infra only)
| Field | Type |
|-------|------|
| id | int |
| created | string |
| content | string |
| status_id | int |

### OrderFileArchive (infra only)
| Field | Type |
|-------|------|
| title | string |
| url | string |

---

## Gotchas & Implementation Notes

1. **`shipping` is array** - API returns `shipping[0]`, not direct object
2. **`preOrder` is required boolean** - not nullable (exists on both Order and OrderProduct)
3. **`customer.type` is string** - 'guest' or 'registered' (docs show int, but old-server string is correct)
4. **`customFields` on OrderProduct** - array of `{name, value}` objects, NOT flat array
5. **Numeric suffixes** - Need explicit `#[MapInputName('address_line_1')]` for address fields
6. **`state` vs `province`** - Address has BOTH fields, separate
7. **`adminComments[].status_id`** - snake_case (unlike other fields)
8. **Summary/Detail fields** - Products, fileArchives, adminComments excluded from summary requests
9. **Search is keyword-based** - Not exact match; callers must verify results

---

## Smoke Test Success Criteria

Before writing unit tests, smoke test must parse **2000+ orders** with:
- [ ] Zero parsing exceptions
- [ ] Various order states (paid, unpaid, cancelled, shipped, refunded)
- [ ] Trade (B2B) and retail (B2C) orders
- [ ] Anonymized orders
- [ ] Orders with/without: discounts, refunds, tracking, delivery dates

---

## Critical Files to Reference

1. `app/Infrastructure/Shopwired/Clients/CustomerClient.php` - Client pattern
2. `app/Infrastructure/Shopwired/CustomerQueryParams.php` - Query params pattern
3. `app/Infrastructure/Shopwired/Responses/Customer.php` - DTO with nested objects
4. `app/Domain/Customer/ValueObjects/Customer.php` - Domain VO pattern
5. `examples/old-server/shopwired/Model/Order/Order.php` - Complete field reference
6. `examples/old-server/shopwired/Model/Order/*.php` - Nested object structures
