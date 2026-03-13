# Fix: ShopWired DTO hardening (ALZ-CORE-4D + partial #261)

## Context

**Sentry ALZ-CORE-4D**: ShopWired sends `null` for `comments` on order product line items. `OrderProductResponse` declares it as non-nullable `string`, causing a `TypeError` crash on `/api/webhooks/shopwired/orders`.

**GitHub #261 (partial)**: Parsers only catch `TypeError`, missing Spatie's `CannotCreateData` exception. (Embed `= []` default removal deferred to a separate update.)

Both are infrastructure-layer fixes. Domain objects stay unchanged.

## Part A: Nullable `comments` (ALZ-CORE-4D)

DB columns are already nullable — `OrderProductModel` and `OrderModelMapper` already coalesce with `?? ''`. Only the webhook/API response DTOs are broken.

### A1. `app/Infrastructure/Shopwired/Responses/OrderProductResponse.php`

- **Constructor (line 52):** `string $comments` → `?string $comments = null`
- **`parsePreorderInfo()` (line 99):** Add early return guard:
  ```php
  if ($this->comments === null || $this->comments === '') {
      return [false, null];
  }
  ```
- **`toDomain()` (line 80):** `$this->comments` → `$this->comments ?? ''`

### A2. `app/Infrastructure/Shopwired/Responses/OrderResponse.php`

- **Constructor (line 75):** `string $comments` → `?string $comments = null`
- **`hasVatRelief()` (line 210):** Add null/empty guard returning `false`
- **`toDomain()` (line 167):** `$this->comments` → `$this->comments ?? ''`
- **`toDomain()` (line 198):** `extractCustomerReferenceNumber($this->comments)` → `extractCustomerReferenceNumber($this->comments ?? '')`

### Domain — No changes

Both `Order.comments` and `OrderProduct.comments` stay `string`. Null is an infrastructure concern.

## Part B: Add `CannotCreateData` to parser catch blocks (#261)

Import: `use Spatie\LaravelData\Exceptions\CannotCreateData;`

Pattern already established in `ShopwiredProductWebhookParser.php` (fixed in #258).

### B1. `app/Infrastructure/Shopwired/Parsers/ShopwiredOrderWebhookParser.php`

3 catch blocks (lines 33, 49, 64): `catch (TypeError $e)` → `catch (TypeError|CannotCreateData $e)`

Add import: `use Spatie\LaravelData\Exceptions\CannotCreateData;`

### B2. `app/Infrastructure/Shopwired/Parsers/ShopwiredCustomerWebhookParser.php`

1 catch block (line 29): `catch (TypeError $e)` → `catch (TypeError|CannotCreateData $e)`

Add import: `use Spatie\LaravelData\Exceptions\CannotCreateData;`

### B3. `app/Infrastructure/Shopwired/Parsers/ShopwiredProductWebhookParser.php`

Already fixed — no changes needed.

## Files Modified

| File | Part | Edits |
|------|------|-------|
| `OrderProductResponse.php` | A1 | 3 |
| `OrderResponse.php` | A2 | 4 |
| `ShopwiredOrderWebhookParser.php` | B1 | 3 + import |
| `ShopwiredCustomerWebhookParser.php` | B2 | 1 + import |

## Commits

- `fix(shopwired): make comments nullable in response DTOs` — Part A. Include `Fixes ALZ-CORE-4D`.
- `fix(shopwired): add CannotCreateData to parser catch blocks (#261)` — Part B.

## Verification

1. `make lint` — PHPStan/Pint/Arkitect pass
2. `make test` — Full test suite passes
