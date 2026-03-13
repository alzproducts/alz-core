# Plan: Prevent silent embed data loss in ShopWired DTOs

## Context

Three Shopwired response DTOs use `= []` defaults on embed properties. When an embed is missing from an API/webhook payload, Spatie hydrates with `[]` instead of throwing — silently overwriting real DB data with empty arrays during upserts.

**Confirmed active data loss**: Customer webhooks persist `customFields: []` to `shopwired.customers.custom_fields`. The follow-up `SyncShopwiredCustomerJob` calls `getCustomerById()` which ALSO omits embeds, so the "correction" re-writes `[]`. Data stays lost until the next scheduled bulk sync.

**Pattern reference**: PR #259 (issue #258) established the `ProductWebhookResponse` dual-DTO pattern with Spatie `Optional` + `presentEmbeds()` + conditional persistence.

---

## Part 1: ProductResponse — Remove `= []`

Webhooks use separate `ProductWebhookResponse`. `ProductClient` always requests embeds via `DEFAULT_EMBEDS`. Safe to make strict.

**File**: `app/Infrastructure/Shopwired/Responses/ProductResponse.php`
- Remove `= []` from: `$variations`, `$images`, `$categories`, `$customFields`, `$filters`

---

## Part 2: CategoryResponse — Remove `= []` defensively + add embeds to client methods

No category table/model/repository exists — categories are read-through only. No data loss risk, but we harden the DTO for correctness.

**File**: `app/Infrastructure/Shopwired/Responses/CategoryResponse.php`
- Remove `= []` from: `$parents`, `$customFields`
- **Reorder constructor params**: Move `?CategoryImageResponse $image = null` AFTER the now-required embed fields to avoid PHP deprecation (required params after optional). `$image` is a standard nullable field, NOT an embed.

**File**: `app/Infrastructure/Shopwired/Clients/CategoryClient.php`
- `getCategoryById()`: Add `DEFAULT_EMBEDS` + `DEFAULT_FIELDS` query params
- `listCategories()`: Add `DEFAULT_EMBEDS` + `DEFAULT_FIELDS` query params

---

## Part 3: CustomerResponse — Full #258 dual-DTO pattern

`ShopwiredCustomerWebhookParser` uses `CustomerResponse::from()` directly. Webhooks don't include embeds, causing silent DB overwrites. Additionally, `getCustomerById()` (called by `SyncShopwiredCustomerJob`) omits embeds — so the "correction" job also writes `[]`.

### 3A: Create CustomerWebhookResponse (NEW)

**File**: `app/Infrastructure/Shopwired/Responses/CustomerWebhookResponse.php`

Mirror `ProductWebhookResponse` pattern:
- Copy all core fields from `CustomerResponse` (id through notes)
- Embed fields use Spatie `Optional`:
  ```php
  public readonly array|Optional $wishlists = new Optional(),
  public readonly array|Optional $customFields = new Optional(),
  ```
- `presentEmbeds()` method: returns `list<string>` of embed names that are not `instanceof Optional`
- `toDomain()` method: coalesces `Optional → []` for domain, otherwise identical to `CustomerResponse::toDomain()`. Must also duplicate `buildAddress()` private helper and `DateTimeImmutable` + `DateMalformedStringException` parsing.
- Keep nullable trade-specific defaults: `$discount = null`, `$costPriceMultiplier = null`, `$creditEnabled = null`

### 3B: Create WebhookCustomerResultDTO (NEW)

**File**: `app/Application/Shopwired/DTOs/WebhookCustomerResultDTO.php`

Mirror `WebhookProductResultDTO`:
```php
final readonly class WebhookCustomerResultDTO
{
    /** @param list<string> $presentEmbeds */
    public function __construct(
        public Customer $customer,
        public array $presentEmbeds,
    ) {}
}
```

### 3C: Update CustomerWebhookParserInterface

**File**: `app/Application/Contracts/Shopwired/CustomerWebhookParserInterface.php`
- Return type: `Customer` → `WebhookCustomerResultDTO`

### 3D: Update ShopwiredCustomerWebhookParser

**File**: `app/Infrastructure/Shopwired/Parsers/ShopwiredCustomerWebhookParser.php`
- **Note**: This file already has uncommitted changes from issue #263 (CannotCreateData added to catch block). Build on top of those changes.
- Use `CustomerWebhookResponse` instead of `CustomerResponse`
- Return `new WebhookCustomerResultDTO($response->toDomain(), $response->presentEmbeds())`
- Keep `TypeError|CannotCreateData` catch block

### 3E: Update HandleCustomerWebhookService

**File**: `app/Application/Shopwired/Services/HandleCustomerWebhookService.php`
- Unpack result: `$result = $this->customerParser->parseCustomer($data)`
- Pass `$result->customer` and `$result->presentEmbeds` to use case

### 3F: Update SyncCustomerUseCase

**File**: `app/Application/Shopwired/UseCases/Webhooks/SyncCustomerUseCase.php`
- Add `array $presentEmbeds = []` parameter to `execute()`
- Pass to repository: `saveFromWebhook($customer, $eventTime, $presentEmbeds)`

### 3G: Update CustomerRepositoryInterface

**File**: `app/Application/Contracts/Shopwired/CustomerRepositoryInterface.php`
- `saveFromWebhook()`: Add `array $presentEmbeds = []` parameter

### 3H: Update CustomerModelMapper — conditional persistence

**File**: `app/Infrastructure/Shopwired/Mappers/CustomerModelMapper.php`
- Extract shared fields to `private static function coreAttributes()` (everything except `custom_fields`)
- Update `toModelAttributes()` to use `coreAttributes()` + `'custom_fields'` (full API/bulk path unchanged)
- Add `toWebhookAttributes(Customer $customer, array $presentEmbeds)`:
  - Always include `coreAttributes()`
  - Conditionally include `'custom_fields'` only if `'custom_fields'` in `$presentEmbeds`
  - `wishlists` not persisted — no conditional needed

### 3I: Update EloquentCustomerRepository

**File**: `app/Infrastructure/Shopwired/Repositories/EloquentCustomerRepository.php`
- `saveFromWebhook()`: Accept `array $presentEmbeds = []`
- When `$presentEmbeds !== []`: use `CustomerModelMapper::toWebhookAttributes()`
- When `$presentEmbeds === []`: use `CustomerModelMapper::toModelAttributes()` (backward compat)

### 3J: Fix CustomerClient.getCustomerById() — add embeds

**File**: `app/Infrastructure/Shopwired/Clients/CustomerClient.php`
- `getCustomerById()`: Add `DEFAULT_EMBEDS` + `DEFAULT_FIELDS` query params
- This fixes `SyncShopwiredCustomerJob` which calls this method for "full sync" after webhook

### 3K: Harden CustomerResponse

**File**: `app/Infrastructure/Shopwired/Responses/CustomerResponse.php`
- Remove `= []` from `$wishlists` and `$customFields`
- **Reorder constructor params**: Move `$discount = null`, `$costPriceMultiplier = null`, `$creditEnabled = null` AFTER the now-required embed fields to avoid PHP deprecation (required params after optional)
- Now strict for API client path only

---

## Part 4: Delete dead code — CachingShopwiredService

`CachingShopwiredService` is never registered in the container and has zero production callers.

**Delete files:**
- `app/Application/Shopwired/Services/CachingShopwiredService.php`
- `tests/Unit/Application/Shopwired/Services/CachingShopwiredServiceTest.php`
- `app/Presentation/Console/Commands/ShopwiredCacheClearCommand.php`
- `tests/Feature/Presentation/Console/Commands/ShopwiredCacheClearCommandTest.php`

---

## Files summary

| File | Action | Part |
|------|--------|------|
| `Responses/ProductResponse.php` | Remove `= []` from 5 fields | 1 |
| `Responses/CategoryResponse.php` | Remove `= []` from 2 fields | 2 |
| `Clients/CategoryClient.php` | Add embeds to 2 methods | 2 |
| `Responses/CustomerWebhookResponse.php` | **NEW** — Spatie Optional DTO | 3A |
| `DTOs/WebhookCustomerResultDTO.php` | **NEW** — embed metadata carrier | 3B |
| `Contracts/CustomerWebhookParserInterface.php` | Return type -> WebhookCustomerResultDTO | 3C |
| `Parsers/ShopwiredCustomerWebhookParser.php` | Use new DTO | 3D |
| `Services/HandleCustomerWebhookService.php` | Unpack result DTO | 3E |
| `UseCases/Webhooks/SyncCustomerUseCase.php` | Add presentEmbeds param | 3F |
| `Contracts/CustomerRepositoryInterface.php` | Add presentEmbeds param | 3G |
| `Mappers/CustomerModelMapper.php` | Add toWebhookAttributes() | 3H |
| `Repositories/EloquentCustomerRepository.php` | Conditional persistence | 3I |
| `Clients/CustomerClient.php` | Add embeds to getCustomerById() | 3J |
| `Responses/CustomerResponse.php` | Remove `= []` from 2 fields | 3K |
| `Services/CachingShopwiredService.php` | **DELETE** | 4 |
| `CachingShopwiredServiceTest.php` | **DELETE** | 4 |
| `ShopwiredCacheClearCommand.php` | **DELETE** | 4 |
| `ShopwiredCacheClearCommandTest.php` | **DELETE** | 4 |

---

## Tests to update

- `ShopwiredCustomerWebhookParserTest` — update for new return type (WebhookCustomerResultDTO)
- `HandleCustomerWebhookServiceTest` — update mock expectations for parser return type
- `SyncCustomerUseCaseTest` — update for new presentEmbeds param
- `CustomerModelMapperTest` — add toWebhookAttributes() tests
- `CustomerWebhookResponseTest` — **NEW** test file for presentEmbeds() + toDomain()

---

## Verification

1. `make lint` — PHPStan/Pint/Arkitect/Deptrac pass
2. `make test` — Full test suite passes
3. Verify no references remain to deleted CachingShopwiredService files
