# Fix: Refund webhook uses refund ID instead of order ID (Sentry ALZ-CORE-4T)

## Context

The `order.refund.created` webhook handler uses `subjectId` (the refund ID) as the order ID, causing `ResourceNotFoundException` when looking up orders. For refund webhooks, ShopWired sets `subjectType: "order_refund"` and `subjectId` to the refund's ID — the actual order ID is in `data.object.orderId`.

**Example**: Refund 128409191 -> `subjectId = 128409191` (refund), `data.object.orderId = 11401148` (order)

The bug is on `HandleOrderWebhookService:58` where `$orderId = IntId::from($subjectId)` assumes subjectId is always the order ID.

---

## Changes

### 1. Create `WebhookOrderRefundResultDTO` (NEW)

**File**: `app/Application/Shopwired/DTOs/WebhookOrderRefundResultDTO.php`

Follows the established `Webhook*ResultDTO` pattern (4 existing examples). Carries the parsed `OrderRefund` alongside the extracted `IntId $orderId`.

```php
final readonly class WebhookOrderRefundResultDTO
{
    public function __construct(
        public IntId $orderId,
        public OrderRefund $refund,
    ) {}
}
```

### 2. Update `OrderWebhookParserInterface` (MODIFY)

**File**: `app/Application/Contracts/Shopwired/OrderWebhookParserInterface.php`

Change `parseOrderRefund()` return type from `OrderRefund` to `WebhookOrderRefundResultDTO`.

### 3. Update `ShopwiredOrderWebhookParser` (MODIFY)

**File**: `app/Infrastructure/Shopwired/Parsers/ShopwiredOrderWebhookParser.php`

The `OrderRefundCreatedResponse` DTO already has `public readonly int $orderId`. Instead of calling `$response->toDomain()` directly, capture the response first, extract `orderId`, then return the wrapper DTO:

```php
$response = OrderRefundCreatedResponse::from($data['object']);
return new WebhookOrderRefundResultDTO(
    orderId: IntId::from($response->orderId),
    refund: $response->toDomain(),
);
```

### 4. Update `HandleOrderWebhookService` (MODIFY)

**File**: `app/Application/Shopwired/Services/HandleOrderWebhookService.php`

Add a private `handleRefundCreated()` method. The `RefundCreated` match arm calls this method, which extracts orderId from the parsed DTO instead of using `subjectId`.

The existing `$orderId = IntId::from($subjectId)` on line 58 stays — it's still used by the `Deleted` and `StatusChanged` match arms.

### 5. Update parser test (MODIFY)

**File**: `tests/Unit/Infrastructure/Shopwired/Parsers/ShopwiredOrderWebhookParserTest.php`

Update happy-path assertion: expect `WebhookOrderRefundResultDTO` with `->orderId->value === 11479559` and `->refund` containing the existing assertions.

---

## Verification

1. `make test` — all existing tests pass
2. `make lint` — PHPStan, Pint, PHPArkitect, Deptrac all clean
3. Parser test validates orderId is extracted from `data.object.orderId`, not from `subjectId`
