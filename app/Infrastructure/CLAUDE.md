# Infrastructure Layer

## Eloquent Repositories

**All new repositories MUST:**
1. Define interface extending `RepositoryWriteInterface` in `Application/Contracts/`
2. Create implementation extending `AbstractEloquentRepository`

## Exception Messages

- **Static messages** — When throwing domain exceptions, keep messages static. Pass dynamic details (IDs, field names, error reasons) as constructor parameters — they become context, not message content.

## Exception Handling: Catch and Translate

Infrastructure **always catches** SDK/HTTP exceptions and **translates** to Domain exceptions. This is where technical → business translation happens.

### Core Pattern: Catch and Translate

- Wrap all external API/SDK calls in try-catch
- **Log technical details first** — SDK error codes, messages, raw responses. These won't exist in the Domain exception
- Differentiate error types and translate:
  - Rate limit / throttle → `ExternalServiceUnavailableException` with `retryAfter`
  - Auth / credential failure → `AuthenticationExpiredException`
  - Connection / timeout / general → `ExternalServiceUnavailableException`

### Nested Pattern: Spatie DTO Validation

When parsing API responses through Spatie DTOs (`::from()`), use a nested try-catch:
- **Inner catch** around `SomeResponse::from($row)` catches `ValidationException` — this is an API contract violation (permanent failure)
  - Log at **CRITICAL** level — code needs immediate update
  - Include **raw response** in log (needed to fix the DTO)
  - Throw `InvalidApiResponseException` — do NOT retry (permanent until code changes)
- **Outer catch** handles API/network errors as usual (transient failures)

### Critical Rules

- ✅ Always log before translating — SDK details won't exist in Domain exception
- ❌ Never let SDK exceptions escape to Application layer
- ❌ Never return empty arrays to hide failures — throw exceptions

## Configuration Validation

Use `RuntimeException` for missing/invalid config values — these are programming mistakes, not runtime conditions.

## Spatie LaravelData

Use for parsing external API responses. Supports `#[MapInputName(SnakeCaseMapper::class)]` for property mapping. ❌ **NOT allowed in Domain layer** — Domain must stay framework-independent.

## Domain-to-Model Mapping

Use `Model::attributesFromDomain($vo)` static methods for converting domain objects to database attributes. Don't inline field-by-field mapping in repositories. See `StockItemSupplierModel::attributesFromDomain()` for the canonical pattern.

- The method does NOT include the parent FK (e.g., `linnworks_order_id`) — that's set by the repository
- Include `created_at`/`updated_at` timestamps (bulk operations bypass Eloquent)
- Repository merges FK + model attributes using spread: `['fk' => $id, ...Model::attributesFromDomain($vo)]`

## Bulk Inserts

`insert()` bypasses Eloquent timestamps — manually add `created_at`/`updated_at` in mapper.

## Client Contracts: Structural Mapping Only

Application-layer interfaces accept **pre-resolved** commands containing opaque external IDs and domain values — the UseCase orchestrates all resolution (SKU→ID, supplier→ID) via separate resolver interfaces.

The Infrastructure client performs **only structural mapping** (key renaming, scalar conversion, null handling) via a plain `readonly` Request class with a static factory:

```
InfraRequest::fromResolved($resolvedId, $domainValue, ...)→toArray()
```

**Resolution is orchestration; orchestration belongs in the UseCase.**

### Request Class Pattern

```php
// Infrastructure/{Integration}/Requests/
final readonly class SomeApiRequest
{
    private function __construct(private string $id, private float $price) {}

    public static function fromResolved(string $id, Guid $guid, Money $price): self
    {
        return new self($id, $price->toNet());
    }

    public function toArray(): array { /* API key names */ }

    // Bulk: accepts the same params as the interface method
    public static function buildBulkPayload(Guid $guid, array $itemPrices): array
    {
        // foreach → fromResolved()→toArray()
    }
}
```

The client method becomes a one-liner — all conversion lives in the Request:

```php
public function updateBulk(Guid $guid, array $prices): void
{
    $this->transport->postFormParams(
        endpoint: '/api/SomeEndpoint',
        params: ['items' => SomeApiRequest::buildBulkPayload($guid, $prices)],
    );
}
```

### Rules

- ✅ `fromResolved()` accepts domain types (Guid, Money) — extracts scalars
- ✅ `toArray()` returns API-specific key names (`StockItemId`, `SupplierID`, etc.)
- ✅ Constructor is `private` — factory is the only entry point
- ❌ No resolution logic (no `resolveStockItemId()`, no `getSupplierByName()`)
- ❌ No business decisions — just shape transformation

**Golden Rule**: Nothing leaves Infrastructure without a Domain exception passport.
