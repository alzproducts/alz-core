# Fix Sentry ALZ-CORE-4A: ShopWired Webhook Date Parsing Failure

## Context

ShopWired sends webhook timestamps in **RFC 2822 format** (`Thu, 12 Mar 2026 18:33:01 +0000`), but Spatie LaravelData's default `DateTimeInterfaceCast` only tries **ISO 8601** (`Y-m-d\TH:i:sP`). This causes a `CannotCastDate` exception on every incoming webhook.

**All 3 webhook controllers are affected** — they all parse via `WebhookEnvelopeDTO::from($request->all())`:
- `ShopwiredWebhookProductController` (the one that triggered the Sentry alert)
- `ShopwiredWebhookOrderController`
- `ShopwiredWebhookCustomerController`

## Fix

### 1. Add `#[WithCast]` to `WebhookEnvelopeDTO::$timestamp`

**File**: `app/Presentation/Http/Shopwired/Webhooks/DTOs/WebhookEnvelopeDTO.php`

Add the Spatie `#[WithCast(DateTimeInterfaceCast::class, format: ['D, d M Y H:i:s O', 'Y-m-d\TH:i:sP'])]` attribute to the `timestamp` property. This tells the cast to try RFC 2822 first (what ShopWired actually sends), falling back to ISO 8601 for resilience.

- `D, d M Y H:i:s O` = PHP's RFC 2822 format (matches `Thu, 12 Mar 2026 18:33:01 +0000`)
- `Y-m-d\TH:i:sP` = Spatie's default ISO 8601 format (kept as fallback)

### 2. Add test for `WebhookEnvelopeDTO` date parsing

**File**: `tests/Unit/Presentation/Http/Shopwired/Webhooks/DTOs/WebhookEnvelopeDTOTest.php` (new)

Test that:
- RFC 2822 timestamps parse correctly (the real-world format)
- ISO 8601 timestamps still parse correctly (fallback)

### 3. Clean up stale `DateMalformedStringException` import

**File**: `app/Presentation/Http/Controllers/Shopwired/Webhooks/ShopwiredWebhookOrderController.php`

Remove the unused `DateMalformedStringException` import on line 14 — the controller has no try-catch and this exception is never thrown from here. (Likely added in anticipation of this exact issue.)

## Verification

1. `make test` — full test suite passes
2. `make lint` — linters pass (Pint, PHPStan, PHPArkitect, Deptrac)
3. Commit references `Fixes ALZ-CORE-4A` to auto-close the Sentry issue
