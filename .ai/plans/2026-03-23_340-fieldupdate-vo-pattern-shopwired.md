# FieldUpdate VO Pattern for ShopWired Entities

## Context

The codebase has two update patterns for ShopWired:
1. **BasicProductUpdateClient** — simple serialize-and-PUT but with complex product-vs-variation routing
2. **ProductUpdateClient** — fetch-merge-PUT for fields needing merge logic (customFields, filters)

Adding a new "simple PUT field" (title, categories, metaTitle, etc.) currently requires a new interface method, a new client method, and wiring — ceremony with no interesting infrastructure behaviour.

**Solution**: A single `{Entity}FieldUpdate` value object with static factory methods per field, backed by one interface and one thin client per entity. Adding a new simple field = one factory method + one `match` arm. No new interfaces, no new classes.

**What stays outside this pattern**: fetch-merge-PUT (customFields, filters), product-vs-variation routing (BasicProductUpdateClient), batch concurrent endpoints (prices, stock), and multi-parameter operations (updateOrderStatus with notifyCustomer/trackingUrl).

---

## Architecture: 4 Files Per Entity

Each entity gets the same structural template:

| Layer | File | Purpose |
|-------|------|---------|
| Domain | `Enums/{Entity}UpdatableField.php` | Enum of updatable fields |
| Domain | `ValueObjects/{Entity}FieldUpdate.php` | VO with static factory methods (type-safe) |
| Application | `Contracts/Shopwired/{Entity}FieldUpdateClientInterface.php` | Interface |
| Infrastructure | `Shopwired/Clients/{Entity}FieldUpdateClient.php` | Client with `match`-based field→API mapping |

**Key design decision**: The VO stores a domain enum + primitive value. The API field name mapping lives in Infrastructure (via `match` expression), keeping Domain free of ShopWired naming. PHPStan validates `match` exhaustiveness — adding an enum case without a mapping is a compile-time error.

---

## Entity Implementations

### 1. Product

**Domain path**: `app/Domain/Catalog/Product/`

#### `Enums/ProductUpdatableField.php`
```php
enum ProductUpdatableField
{
    case Title;
    case Description;
    case MetaTitle;
    case MetaDescription;
    case Categories;
}
```

#### `ValueObjects/ProductFieldUpdate.php`
```php
final readonly class ProductFieldUpdate
{
    private function __construct(
        public ProductUpdatableField $field,
        public string|int|float|bool|array $value,
    ) {}

    public static function title(string $title): self
    {
        return new self(ProductUpdatableField::Title, $title);
    }

    public static function description(string $description): self
    {
        return new self(ProductUpdatableField::Description, $description);
    }

    public static function metaTitle(string $metaTitle): self
    {
        return new self(ProductUpdatableField::MetaTitle, $metaTitle);
    }

    public static function metaDescription(string $metaDescription): self
    {
        return new self(ProductUpdatableField::MetaDescription, $metaDescription);
    }

    /** @param list<int> $categoryIds */
    public static function categories(array $categoryIds): self
    {
        return new self(ProductUpdatableField::Categories, $categoryIds);
    }
}
```

#### `Application/Contracts/Shopwired/ProductFieldUpdateClientInterface.php`
```php
interface ProductFieldUpdateClientInterface
{
    /**
     * @throws ResourceNotAvailableException When product not found (404)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function update(int $productId, ProductFieldUpdate ...$updates): void;
}
```

#### `Infrastructure/Shopwired/Clients/ProductFieldUpdateClient.php`
```php
final readonly class ProductFieldUpdateClient implements ProductFieldUpdateClientInterface
{
    private const string ENDPOINT = 'products';

    public function __construct(
        private ShopwiredTransportInterface $transport,
    ) {}

    public function update(int $productId, ProductFieldUpdate ...$updates): void
    {
        if ($updates === []) {
            return;
        }

        $payload = [];
        foreach ($updates as $update) {
            $payload[self::mapField($update->field)] = $update->value;
        }

        $this->transport->put(self::ENDPOINT . '/' . $productId, $payload);
    }

    private static function mapField(ProductUpdatableField $field): string
    {
        return match ($field) {
            ProductUpdatableField::Title => 'title',
            ProductUpdatableField::Description => 'description',
            ProductUpdatableField::MetaTitle => 'metaTitle',
            ProductUpdatableField::MetaDescription => 'metaDescription',
            ProductUpdatableField::Categories => 'categories',
        };
    }
}
```

**Service provider binding** in `ShopwiredServiceProvider.php`:
```php
$this->app->scoped(
    ProductFieldUpdateClientInterface::class,
    static fn(): ProductFieldUpdateClientInterface => new ProductFieldUpdateClient(
        ShopwiredClientFactory::getTransport(),
    ),
);
```

---

### 2. Customer

**Domain path**: `app/Domain/Customer/`

#### Starter fields (verify against API):
| Factory Method | Type | API Field |
|---|---|---|
| `firstName(string)` | string | `firstName` |

#### Files:
- `app/Domain/Customer/Enums/CustomerUpdatableField.php`
- `app/Domain/Customer/ValueObjects/CustomerFieldUpdate.php`
- `app/Application/Contracts/Shopwired/CustomerFieldUpdateClientInterface.php`
- `app/Infrastructure/Shopwired/Clients/CustomerFieldUpdateClient.php`

**Endpoint**: `PUT customers/{id}`

---

### 3. Category

**Domain path**: `app/Domain/Catalog/Category/` (exists; add `ValueObjects/` subdir)

#### Starter fields (verify against API):
| Factory Method | Type | API Field |
|---|---|---|
| `title(string)` | string | `title` |

#### Files:
- `app/Domain/Catalog/Category/Enums/CategoryUpdatableField.php`
- `app/Domain/Catalog/Category/ValueObjects/CategoryFieldUpdate.php`
- `app/Application/Contracts/Shopwired/CategoryFieldUpdateClientInterface.php`
- `app/Infrastructure/Shopwired/Clients/CategoryFieldUpdateClient.php`

**Endpoint**: `PUT categories/{id}`

---

### 4. Brand

**Domain path**: `app/Domain/Catalog/Brand/` (exists; add `ValueObjects/` subdir)

#### Starter fields (verify against API):
| Factory Method | Type | API Field |
|---|---|---|
| `title(string)` | string | `title` |

#### Files:
- `app/Domain/Catalog/Brand/Enums/BrandUpdatableField.php`
- `app/Domain/Catalog/Brand/ValueObjects/BrandFieldUpdate.php`
- `app/Application/Contracts/Shopwired/BrandFieldUpdateClientInterface.php`
- `app/Infrastructure/Shopwired/Clients/BrandFieldUpdateClient.php`

**Endpoint**: `PUT brands/{id}`

---

## Files to Modify

| File | Change |
|------|--------|
| `app/Providers/ShopwiredServiceProvider.php` | Add 4 new scoped bindings in `register()` + 4 entries in `provides()` array + imports |
| `phparkitect.php` | Verify new `*Client` naming passes (should pass — matches `*Client` convention) |

---

## Implementation Order

1. **Product** — full implementation + tests (reference implementation, all 5 fields)
2. **Customer** — uses existing `Domain/Customer/` (1 field: firstName)
3. **Category** — add `ValueObjects/` subdir to existing `Domain/Catalog/Category/` (1 field: title)
4. **Brand** — add `ValueObjects/` subdir to existing `Domain/Catalog/Brand/` (1 field: title)
5. **Service provider** — all 4 bindings
6. **Deptrac/PHPArkitect** — verify no layer violations

---

## Existing Code to Reuse

| What | Where |
|------|-------|
| `ShopwiredTransportInterface` | `app/Infrastructure/Shopwired/Contracts/ShopwiredTransportInterface.php` |
| `ShopwiredClientFactory::getTransport()` | `app/Infrastructure/Shopwired/ShopwiredClientFactory.php` |
| Domain exception imports | `app/Domain/Exceptions/Api/` (same @throws as existing clients) |
| Service provider pattern | `app/Providers/ShopwiredServiceProvider.php` (scoped bindings, lines 127-142) |

---

## Testing Strategy

### Unit Tests (per entity)

**VO tests** — verify factory methods produce correct field + value:
```php
it('creates title update', function () {
    $update = ProductFieldUpdate::title('New Title');
    expect($update->field)->toBe(ProductUpdatableField::Title);
    expect($update->value)->toBe('New Title');
});

it('creates categories update with list of IDs', function () {
    $update = ProductFieldUpdate::categories([1, 2, 3]);
    expect($update->field)->toBe(ProductUpdatableField::Categories);
    expect($update->value)->toBe([1, 2, 3]);
});
```

**Client tests** — verify payload construction and transport calls:
```php
it('builds correct payload from multiple updates', function () {
    $transport = Mockery::mock(ShopwiredTransportInterface::class);
    $transport->shouldReceive('put')
        ->once()
        ->with('products/42', [
            'title' => 'New Title',
            'metaTitle' => 'SEO Title',
        ]);

    $client = new ProductFieldUpdateClient($transport);
    $client->update(42,
        ProductFieldUpdate::title('New Title'),
        ProductFieldUpdate::metaTitle('SEO Title'),
    );
});

it('no-ops on empty updates', function () {
    $transport = Mockery::mock(ShopwiredTransportInterface::class);
    $transport->shouldNotReceive('put');

    $client = new ProductFieldUpdateClient($transport);
    $client->update(42);
});
```

### Test File Locations

- `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductFieldUpdateTest.php`
- `tests/Unit/Infrastructure/Shopwired/Clients/ProductFieldUpdateClientTest.php`
- (Same pattern for other entities)

---

## Verification

1. `make fix` — auto-fix code style
2. `make lint` — PHPStan, PHPArkitect, Deptrac pass (especially layer boundaries)
3. `make test` — all new + existing tests pass
4. Verify `match` exhaustiveness — add a field to enum, confirm PHPStan catches missing arm
