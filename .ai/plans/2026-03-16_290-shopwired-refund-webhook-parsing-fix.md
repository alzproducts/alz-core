# Fix: Sentry ALZ-CORE-4R — ShopWired refund webhook parsing failure

## Context

Sentry issue ALZ-CORE-4R (33 occurrences, escalating since 2026-03-16 10:33 UTC) — **all `order.refund.created` webhooks are failing**. The `ShopwiredOrderWebhookParser::parseOrderRefund()` method cannot parse the incoming payload due to two compounding bugs:

1. **Missing nested key extraction** — passes raw `$data` instead of `$data['object']`
2. **Wrong SnakeCaseMapper** — the refund payload uses camelCase keys, not snake_case

Even fixing bug #1 alone would still fail: `orderId` and `createdAt` would not be matched by the mapper (it looks for `order_id` and `created_at`).

### Actual payload (confirmed from Sentry)

```json
{
  "data": {
    "object": {
      "amount": 14.95,
      "createdAt": "Mon, 16 Mar 2026 10:35:33 +0000",
      "description": "Date:16-03-2026>14.95*DOR-SSD1*Not big enough>",
      "id": 128409325,
      "orderId": 11479559
    }
  }
}
```

Keys are **camelCase** — `orderId`, `createdAt` — not snake_case.

---

## Changes

### 1. Fix parser: extract `$data['object']` + add key guard

**File**: `app/Infrastructure/Shopwired/Parsers/ShopwiredOrderWebhookParser.php`

In `parseOrderRefund()`:
- Add `array_key_exists('object', $data)` guard (matching `ShopwiredProductWebhookParser` pattern)
- Change `OrderRefundCreatedResponse::from($data)` to `OrderRefundCreatedResponse::from($data['object'])`

### 2. Remove SnakeCaseMapper from refund DTO

**File**: `app/Infrastructure/Shopwired/Responses/OrderRefundCreatedResponse.php`

- Remove `#[MapInputName(SnakeCaseMapper::class)]` class attribute
- Remove unused imports: `Spatie\LaravelData\Attributes\MapInputName`, `Spatie\LaravelData\Mappers\SnakeCaseMapper`

### 3. Add test for `parseOrderRefund()`

**File**: `tests/Unit/Infrastructure/Shopwired/Parsers/ShopwiredOrderWebhookParserTest.php` (new)

Test cases:
- Happy path: valid refund payload nested in `object` key → returns `OrderRefund` domain object with correct values
- Missing `object` key → throws `InvalidApiResponseException`
- Missing required fields in `object` → throws `InvalidApiResponseException`

Uses the sample payload from the Sentry event as the fixture.

---

## Verification

1. `make test` — ensure new tests pass
2. `make lint` — ensure code quality
3. After deploy: monitor Sentry ALZ-CORE-4R — should stop receiving new events
