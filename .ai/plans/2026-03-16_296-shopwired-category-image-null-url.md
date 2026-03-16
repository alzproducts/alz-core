# Fix: ShopWired category image null URL crash (ALZ-CORE-4S)

## Context

Sentry issue ALZ-CORE-4S (8 occurrences, first seen 2026-03-16). ShopWired sends category webhook payloads where the image object exists but has a null URL: `{"image": {"url": null}}`. The `CategoryImageResponse` DTO requires non-nullable `string $url`, causing a `TypeError` that surfaces as `InvalidApiResponseException`.

**Goal**: Handle null image URLs gracefully — treat `{"image": {"url": null}}` as "no image" at the infrastructure boundary. Domain stays unchanged (an image always has a URL).

## Changes

### 1. Make image DTO `$url` nullable

**Files:**
- `app/Infrastructure/Shopwired/Responses/CategoryImageResponse.php:22`
- `app/Infrastructure/Shopwired/Responses/BrandImageResponse.php:22` (preemptive — same pattern)

Change: `public readonly string $url` → `public readonly ?string $url`

Add `Webmozart\Assert::notNull()` in `toDomain()` to enforce the contract — callers must check `$url` before calling `toDomain()`.

### 2. Filter null-URL images in parent DTOs

In each parent DTO's `toDomain()`, change the image mapping from:
```php
image: $this->image?->toDomain(),
```
to:
```php
image: $this->image?->url !== null ? $this->image->toDomain() : null,
```

**Files:**
- `app/Infrastructure/Shopwired/Responses/CategoryWebhookResponse.php:121`
- `app/Infrastructure/Shopwired/Responses/CategoryResponse.php:93`
- `app/Infrastructure/Shopwired/Responses/BrandWebhookResponse.php:99`
- `app/Infrastructure/Shopwired/Responses/BrandResponse.php:82`

### Not changed
- Domain value objects (`CategoryImage`, `BrandImage`) — keep `string $url` (non-nullable). An image without a URL is not a valid domain object.
- `DomainConvertibleInterface` — no changes needed.

## Verification

1. `make test` — existing tests pass
2. `make lint` — Pint + PHPStan + PHPArkitect + Deptrac pass
3. Verify Sentry issue stops recurring after deploy
