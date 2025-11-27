# CustomerClient Implementation Plan

## Overview

Implement `CustomerClient` for ShopWired API following the established `CategoryClient` pattern, with:
- Interface-based paginatable query params (Option 2)
- Hybrid filter approach with dedicated methods for cacheable combinations
- Full embed support (country, state, wishlists, custom_fields)

## Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Filter API | Hybrid approach | `listAllCustomers()` + `listAllTradeCustomers()` + `searchByEmail()` - clear caching boundaries |
| Email search return | `?Customer` | Assumes email uniqueness, simpler API |
| Default embeds | All (country, state, wishlists, custom_fields) | Complete data for business operations |
| Wishlists in Domain | Include as embedded VO | Convenience outweighs coupling concerns |
| Query params | Interface + Composition | `PaginatableQueryParams` interface for generic paginator |

---

## Implementation Order

### Phase 0: Pre-Implementation Discovery

**Task: Test custom_fields embed on CategoryClient**
- Before creating CustomField VO, verify that `custom_fields` embed works on `/categories` endpoint
- Make a test API call with `?embed=custom_fields` to confirm response structure
- If supported, update existing Category domain/DTO to include `customFields` property
- This determines whether CustomField is shared infrastructure or Customer-specific

### Phase 1: Interface for Paginatable Params

**File 1: `app/Infrastructure/Shopwired/PaginatableQueryParams.php`** (Create)
```php
interface PaginatableQueryParams
{
    public int $count { get; }
    public function nextPage(): static;
    /** @return array<string, int|string> */
    public function toArray(): array;
}
```

**File 2: `app/Infrastructure/Shopwired/ShopwiredQueryParams.php`** (Modify)
- Add `implements PaginatableQueryParams` (no other changes needed)

**File 3: `app/Infrastructure/Shopwired/ShopwiredPaginator.php`** (Modify)
- Change parameter type: `ShopwiredQueryParams` → `PaginatableQueryParams`

### Phase 2: Customer Query Params

**File 4: `app/Infrastructure/Shopwired/CustomerQueryParams.php`** (Create)
```php
final readonly class CustomerQueryParams implements PaginatableQueryParams
{
    public function __construct(
        private ShopwiredQueryParams $base,
        public ?int $trade = null,      // 0=retail, 1=trade, null=all
        public ?string $email = null,
    ) {}

    // PHP 8.4 property hook
    public int $count { get => $this->base->count; }

    // Static factories
    public static function forBulkFetch(): self
    public static function forTradeCustomers(): self
    public static function forRetailCustomers(): self

    // Fluent builders preserving immutability
    public function withSort(CustomerSort $sort): self
    public function withEmail(string $email): self
    public function withEmbeds(array $embeds): self
    public function nextPage(): static
    public function toArray(): array  // Merges base + trade + email
}
```

### Phase 3: Domain Value Objects

**File 5: `app/Domain/Catalog/ValueObjects/CustomField.php`** (Create)
```php
final readonly class CustomField
{
    public function __construct(
        public int $id,
        public string $name,           // max 40 chars, alphanumeric + underscore
        public CustomFieldType $type,  // Enum: text, toggle, choice, list, date, date_time, value_list, product_list
        public string $label,
        public CustomFieldItemType $itemType,  // Enum: product, category, brand, order, page, blog_post
        public int $sortOrder,
        /** @var list<string> */
        public array $allowedValues = [],  // For choice/list types only
    ) {}
}
```

**File 6: `app/Domain/Catalog/Enums/CustomFieldType.php`** (Create)
```php
enum CustomFieldType: string
{
    case Text = 'text';
    case Toggle = 'toggle';
    case Choice = 'choice';
    case List = 'list';
    case Date = 'date';
    case DateTime = 'date_time';
    case ValueList = 'value_list';
    case ProductList = 'product_list';
}
```

**File 7: `app/Domain/Catalog/Enums/CustomFieldItemType.php`** (Create)
```php
enum CustomFieldItemType: string
{
    case Product = 'product';
    case Category = 'category';
    case Brand = 'brand';
    case Order = 'order';
    case Page = 'page';
    case BlogPost = 'blog_post';
}
```

**File 8: `app/Domain/Customer/ValueObjects/Country.php`** (Create)
```php
final readonly class Country
{
    public function __construct(
        public string $name,
        public string $iso,  // 2-char ISO code
    ) {}
}
```

**File 9: `app/Domain/Customer/ValueObjects/State.php`** (Create)
```php
final readonly class State
{
    public function __construct(public string $name) {}
}
```

**File 10: `app/Domain/Customer/ValueObjects/Wishlist.php`** (Create)
```php
final readonly class Wishlist
{
    public function __construct(
        public int $id,
        public int $token,
        public bool $isPublic,
    ) {}
}
```

**File 11: `app/Domain/Customer/ValueObjects/CustomerAddress.php`** (Create)
```php
final readonly class CustomerAddress
{
    public function __construct(
        public ?string $line1,
        public ?string $line2,
        public ?string $line3,
        public ?string $city,
        public ?string $province,
        public ?string $postcode,
        public ?Country $country,
        public ?State $state,
    ) {}

    public function isShippable(): bool  // Has required fields
}
```

**File 12: `app/Domain/Customer/ValueObjects/Customer.php`** (Create)
```php
final readonly class Customer
{
    public function __construct(
        // Identity
        public string $email,
        public string $firstName,
        public string $lastName,
        public ?string $companyName,

        // Classification
        public bool $isTrade,
        public bool $isActive,
        public bool $creditEnabled,

        // Pricing
        public float $discount,
        public float $costPriceMultiplier,

        // Contact
        public ?string $phone,
        public ?string $mobilePhone,
        public ?string $website,
        public ?string $vatNumber,
        public bool $acceptsMarketing,

        // Address
        public ?CustomerAddress $address,

        // Loyalty
        public int $rewardPoints,

        // Notes
        public ?string $notes,

        // Wishlists (embedded)
        /** @var list<Wishlist> */
        public array $wishlists = [],
    ) {
        // Webmozart assertions for invariants
    }

    public function fullName(): string
    public function qualifiesForTradePricing(): bool
}
```

### Phase 4: Infrastructure Response DTOs

**File 13: `app/Infrastructure/Shopwired/Responses/CustomField.php`** (Create)
```php
#[MapInputName(SnakeCaseMapper::class)]
final class CustomField extends Data implements DomainConvertible
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $type,        // Parsed to enum in toDomain()
        public readonly string $label,
        public readonly string $itemType,    // Parsed to enum in toDomain()
        public readonly int $sortOrder,
        /** @var list<string> */
        public readonly array $allowedValues = [],
    ) {}

    public function toDomain(): DomainCustomField { /* ... */ }
}
```

**File 14: `app/Infrastructure/Shopwired/Responses/CustomerCountry.php`** (Create)
- Spatie Data DTO with `#[MapInputName(SnakeCaseMapper::class)]`
- Implements `DomainConvertible` → `Country`

**File 15: `app/Infrastructure/Shopwired/Responses/CustomerState.php`** (Create)
- Same pattern → `State`

**File 16: `app/Infrastructure/Shopwired/Responses/CustomerWishlist.php`** (Create)
- Same pattern → `Wishlist`

**File 17: `app/Infrastructure/Shopwired/Responses/Customer.php`** (Create)
```php
#[MapInputName(SnakeCaseMapper::class)]
final class Customer extends Data implements DomainConvertible
{
    public function __construct(
        public readonly int $id,              // Infrastructure only
        public readonly string $createdAt,    // Infrastructure only
        public readonly bool $trade,
        public readonly ?int $tradeGroupId,   // Infrastructure only
        public readonly bool $active,
        public readonly bool $adminCreated,   // Infrastructure only
        public readonly bool $autoCreated,    // Infrastructure only
        // ... all API fields
        public readonly ?CustomerCountry $country,
        public readonly ?CustomerState $state,
        #[DataCollectionOf(CustomerWishlist::class)]
        public readonly array $wishlists = [],
    ) {}

    public function toDomain(): DomainCustomer
    {
        // Map to Domain VO, excluding id/createdAt/audit fields
        // Build CustomerAddress from flat address fields
    }
}
```

### Phase 5: Application Interface

**File 18: `app/Application/Contracts/Shopwired/CustomerClientInterface.php`** (Create)
```php
interface CustomerClientInterface
{
    /** @return list<Customer> */
    public function listAllCustomers(?CustomerSort $sort = null): array;

    /** @return list<Customer> */
    public function listAllTradeCustomers(?CustomerSort $sort = null): array;

    /** @return list<Customer> */
    public function listCustomers(): array;

    public function getCustomerById(int $id): Customer;

    public function getCustomerCount(): int;

    public function searchByEmail(string $email): ?Customer;
}
```
All methods `@throws ExternalServiceUnavailableException, InvalidApiResponseException`

### Phase 6: Client Implementation

**File 19: `app/Infrastructure/Shopwired/Clients/CustomerClient.php`** (Create)
```php
final readonly class CustomerClient implements CustomerClientInterface
{
    use ShopwiredResponseParserTrait;

    private const string ENDPOINT = 'customers';
    private const array DEFAULT_EMBEDS = ['country', 'state', 'wishlists', 'custom_fields'];

    public function listAllCustomers(?CustomerSort $sort = null): array
    {
        $total = $this->getCustomerCount();
        $params = CustomerQueryParams::forBulkFetch()
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withSort($sort ?? CustomerSort::CreatedDesc);

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(CustomerQueryParams $p) => $this->fetchPage($p),
            knownTotal: $total,  // Optimization for 10+ pages
        );
    }

    public function listAllTradeCustomers(?CustomerSort $sort = null): array
    {
        $params = CustomerQueryParams::forTradeCustomers()
            ->withEmbeds(self::DEFAULT_EMBEDS)
            ->withSort($sort ?? CustomerSort::CreatedDesc);

        return ShopwiredPaginator::fetchAll(
            params: $params,
            fetchPage: fn(CustomerQueryParams $p) => $this->fetchPage($p),
        );
    }

    public function searchByEmail(string $email): ?Customer
    {
        $params = (new CustomerQueryParams(
            base: new ShopwiredQueryParams(count: 1),
            email: $email,
        ))->withEmbeds(self::DEFAULT_EMBEDS);

        $response = $this->transport->get(self::ENDPOINT, $params->toArray());
        $customers = self::parseArrayToDomain($response->json(), CustomerResponse::class);

        return $customers[0] ?? null;
    }

    // ... remaining methods follow CategoryClient pattern
}
```

### Phase 7: Factory & Provider

**File 20: `app/Infrastructure/Shopwired/ShopwiredClientFactory.php`** (Modify)
- Add `createCustomerClient(): CustomerClientInterface`

**File 21: `app/Providers/ShopwiredServiceProvider.php`** (Modify)
- Add singleton binding for `CustomerClientInterface`
- Update `provides()` array

### Phase 8: Tests

**File 22: `tests/Unit/Infrastructure/Shopwired/PaginatableQueryParamsTest.php`** (Create)
- Interface contract tests

**File 23: `tests/Unit/Infrastructure/Shopwired/CustomerQueryParamsTest.php`** (Create)
- Construction, validation, fluent builders, nextPage preservation

**File 24: `tests/Unit/Infrastructure/Shopwired/Clients/CustomerClientTest.php`** (Create)
- Mirror CategoryClientTest structure
- Test all interface methods
- Mock transport responses

**File 25: `tests/Unit/Domain/Customer/ValueObjects/CustomerTest.php`** (Create)
- Value object assertions, fullName(), qualifiesForTradePricing()

---

## File Summary

| # | File | Action |
|---|------|--------|
| 1 | `Infrastructure/Shopwired/PaginatableQueryParams.php` | Create |
| 2 | `Infrastructure/Shopwired/ShopwiredQueryParams.php` | Modify (add implements) |
| 3 | `Infrastructure/Shopwired/ShopwiredPaginator.php` | Modify (param type) |
| 4 | `Infrastructure/Shopwired/CustomerQueryParams.php` | Create |
| 5 | `Domain/Catalog/ValueObjects/CustomField.php` | Create |
| 6 | `Domain/Catalog/Enums/CustomFieldType.php` | Create |
| 7 | `Domain/Catalog/Enums/CustomFieldItemType.php` | Create |
| 8-12 | `Domain/Customer/ValueObjects/*.php` | Create (5 files: Country, State, Wishlist, CustomerAddress, Customer) |
| 13 | `Infrastructure/Shopwired/Responses/CustomField.php` | Create |
| 14-17 | `Infrastructure/Shopwired/Responses/Customer*.php` | Create (4 files: CustomerCountry, CustomerState, CustomerWishlist, Customer) |
| 18 | `Application/Contracts/Shopwired/CustomerClientInterface.php` | Create |
| 19 | `Infrastructure/Shopwired/Clients/CustomerClient.php` | Create |
| 20-21 | Factory + Provider | Modify |
| 22-25 | Tests | Create (4 files) |

**Total: 21 new files, 4 modified files**

**Conditional (if custom_fields works on Categories):**
- Modify `Domain/Catalog/ValueObjects/Category.php` - add `customFields` property
- Modify `Infrastructure/Shopwired/Responses/Category.php` - add `customFields` array

---

## Critical Reference Files

Read these before implementing:
- `/app/Infrastructure/Shopwired/Clients/CategoryClient.php` - Client pattern
- `/app/Infrastructure/Shopwired/Responses/Category.php` - Response DTO pattern
- `/app/Domain/Catalog/ValueObjects/Category.php` - Domain VO pattern
- `/app/Infrastructure/Shopwired/ShopwiredPaginator.php` - Paginator (has $knownTotal)
- `/tests/Unit/Infrastructure/Shopwired/Clients/CategoryClientTest.php` - Test pattern

---

## Notes

1. **Caching**: `listAllCustomers()` and `listAllTradeCustomers()` are cacheable at service layer; `searchByEmail()` is not
2. **Pagination optimization**: Use `getCustomerCount()` + `$knownTotal` param for efficient bulk fetches
3. **PHP 8.4**: Use property hooks for `$count` delegation in `CustomerQueryParams`
4. **Backwards compatible**: CategoryClient unchanged - ShopwiredQueryParams implements new interface
