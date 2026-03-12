# Fix: WebhookResponse `address` / `url` Field Mismatch

## Context

Production error from `ProcessShopwiredWebhookHealthJob`:

```
Could not create WebhookResponse: the constructor requires 5 parameters, 4 given.
Parameters missing: address.
```

**Root cause**: The ShopWired webhooks API returns the endpoint URL under the key `url`, but `WebhookResponse` declares a property named `address`. The class-level `#[MapInputName(SnakeCaseMapper::class)]` only handles snake_case→camelCase conversion — it does NOT rename `url` → `address`. Spatie Data throws `CannotCreateData` because `address` is missing from the parsed input, which the `ShopwiredResponseParserTrait` catches and re-throws as `InvalidApiResponseException`.

**Important**: These files live on `develop` (PR #241), not on the current worktree branch. Work on a new `fix/webhook-url-field` branch off `develop`.

---

## Fix

### File 1: `app/Infrastructure/Shopwired/Responses/WebhookResponse.php`

Add a property-level `#[MapInputName('url')]` attribute to the `address` property. In Spatie LaravelData, a property-level `MapInputName` overrides the class-level mapper for that specific property.

```php
#[MapInputName('url')]
public readonly string $address,
```

Full property block after fix:
```php
public function __construct(
    public readonly int $id,
    public readonly string $topic,
    #[MapInputName('url')]
    public readonly string $address,
    public readonly bool $enabled,
    public readonly bool $verified,
) {}
```

No other domain files change — `WebhookDTO`, `CheckShopwiredWebhookHealthUseCase`, and their tests all correctly use `address` as the semantic term.

---

### File 2: `tests/Feature/Infrastructure/Api/WebhookClientTest.php`

The test fixture uses `'address'` as the key in fake HTTP responses — this was wrong from day one (matched the DTO property name, not the real API). Update to `'url'`:

In `it_returns_parsed_webhook_list_on_success`, change:
```php
// Before (wrong — mirrors DTO property, not real API)
'address' => 'https://example.com/webhooks/orders',
// ...
'address' => 'https://example.com/webhooks/products',

// After (correct — mirrors real API field name)
'url' => 'https://example.com/webhooks/orders',
// ...
'url' => 'https://example.com/webhooks/products',
```

---

## Files to Modify

| File | Change |
|------|--------|
| `app/Infrastructure/Shopwired/Responses/WebhookResponse.php` | Add `#[MapInputName('url')]` to `address` property |
| `tests/Feature/Infrastructure/Api/WebhookClientTest.php` | Update fixture keys from `address` → `url` |

---

## Not Changing

- `app/Application/Shopwired/DTOs/WebhookDTO.php` — `address` is the correct domain term
- `app/Application/Shopwired/UseCases/CheckShopwiredWebhookHealthUseCase.php` — uses `$webhook->address` correctly
- `tests/Unit/Application/Shopwired/UseCases/CheckShopwiredWebhookHealthUseCaseTest.php` — creates `WebhookDTO` directly (no API parsing), `address` param is correct

---

## Verification

1. `make test` — both `WebhookClientTest` and `CheckShopwiredWebhookHealthUseCaseTest` must pass
2. `make lint` — no PHPStan/Pint issues (Spatie Data import for `MapInputName` already exists in the file)
