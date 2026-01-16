# ShopWired Product Sync Implementation Plan

## Overview

Implement full product synchronization from ShopWired API to local database, following the established patterns from `SyncShopwiredOrdersJob` and `SyncShopwiredCustomersJob`.

**Scope**: Standard (core fields + pricing + variations + images + custom fields)
**Catalog Size**: ~1,000-1,500 products
**Estimated Sync Time**: ~2-5 minutes full sync

---

## Architecture Summary

```
Job (SyncShopwiredProductsJob)
  → UseCase (SyncProductsUseCase)
    → Client (ProductClient via ProductClientInterface)
        → Factory (ProductDomainFactory) ← Registry (CustomFieldDefinitionRegistry)
        → Transport (ShopwiredHttpTransport)
    → Repository (EloquentProductRepository via ProductRepositoryInterface)
      → Models (ProductModel, ProductVariationModel)
        → Database (shopwired.products, shopwired.product_variations)
```

### Custom Fields Data Flow

```
API JSON (customFields: {name: value})
  → ProductResponse (DTO, raw customFields array)
  → ProductDomainFactory
      ↓ looks up definition by name
      CustomFieldDefinitionRegistry (in-memory, keyed by name)
      ↓ creates typed value object
  → Product (Domain VO with list<CustomFieldValue>)
```

**Key Decision**: Factory lazy-loads registry on first use. Registered with `scoped()` binding to ensure fresh instance per queue job (avoids Octane stale state).

---

## Implementation Phases

### Phase 1: Domain Layer

#### Phase 1a: Custom Field Value Objects (Generic, Reusable)

| File | Description |
|------|-------------|
| `app/Domain/Catalog/CustomFields/ValueObjects/AbstractCustomFieldValue.php` | Abstract base with embedded CustomFieldDefinition |
| `app/Domain/Catalog/CustomFields/ValueObjects/StringCustomFieldValue.php` | Text, Choice, List types (string values) |
| `app/Domain/Catalog/CustomFields/ValueObjects/DateTimeCustomFieldValue.php` | Date, DateTime types (DateTimeImmutable value) - **TODO: verify API datetime format (assumed Unix timestamp)** |
| `app/Domain/Catalog/CustomFields/ValueObjects/ToggleCustomFieldValue.php` | Boolean toggle type |
| `app/Domain/Catalog/CustomFields/ValueObjects/ValueListCustomFieldValue.php` | Array of strings type |
| `app/Domain/Catalog/CustomFields/ValueObjects/ProductListCustomFieldValue.php` | Array of product IDs type |
| `app/Domain/Catalog/CustomFields/Exceptions/CustomFieldNotFoundException.php` | Thrown when field name not in registry |
| `app/Domain/Catalog/CustomFields/Exceptions/InvalidCustomFieldValueException.php` | Thrown when value type mismatches definition |

**CustomFieldValue Design**:
```php
abstract readonly class CustomFieldValue
{
    public function __construct(
        public CustomFieldDefinition $definition,
    ) {}

    abstract public function rawValue(): mixed;

    // Convenience accessors
    public function name(): string => $this->definition->name;
    public function label(): ?string => $this->definition->label;
    public function type(): CustomFieldType => $this->definition->type;
}

final readonly class StringCustomFieldValue extends CustomFieldValue
{
    public function __construct(
        CustomFieldDefinition $definition,
        public string $value,
    ) {
        parent::__construct($definition);
    }
}
```

**Note**: These are generic and reusable across Products, Customers, Orders, etc.

#### Phase 1b: Product Value Objects (Existing + Update)

| File | Description |
|------|-------------|
| `app/Domain/Catalog/Product/ValueObjects/ProductImage.php` | ✅ EXISTS - Image with id, url, description, sortOrder |
| `app/Domain/Catalog/Product/ValueObjects/ProductVariationOption.php` | ✅ EXISTS - Option attribute (optionId, optionName, valueId, valueName) |
| `app/Domain/Catalog/Product/ValueObjects/ProductVariation.php` | ✅ EXISTS - Variation with pricing, stock, options array |
| `app/Domain/Catalog/Product/ValueObjects/Product.php` | ⚠️ UPDATE NEEDED - Add customFields property |

**Key Properties (Product)**:
- Identifiers: `id`, `sku`, `slug`, `url`
- Pricing: `price`, `costPrice`, `salePrice`, `comparePrice`
- Inventory: `stock`
- Flags: `isActive`, `vatExclusive`, `vatRelief`
- Shipping: `weight`
- SEO: `metaTitle`, `metaDescription`
- Relations: `categoryIds[]`, `variations[]`, `images[]`
- **Custom Fields**: `customFields: list<CustomFieldValue>` ← NEW
- Timestamps: `createdAt`, `updatedAt` (from ShopWired)

### Phase 2: Database Migrations

| File | Description |
|------|-------------|
| `create_shopwired_products_table.php` | Main products table with JSONB for category_ids and images |
| `create_shopwired_product_variations_table.php` | Child table with composite unique (product_external_id, external_id) |

**Products Table Schema**:
```
shopwired.products
├── id (UUID primary)
├── external_id (int unique) - ShopWired product ID
├── sku, title, description, slug, url
├── price, cost_price, sale_price, compare_price (decimal 14,6)
├── stock
├── is_active, vat_exclusive, vat_relief
├── weight
├── meta_title, meta_description
├── category_ids (JSONB array of ints)
├── images (JSONB array of {id, url, description, sort_order})
├── custom_fields (JSONB object {name: value, ...}) ← NEW
├── shopwired_created_at, shopwired_updated_at
└── created_at, updated_at
```

**Note**: `custom_fields` stored as raw JSONB from API. Typed `CustomFieldValue` objects are hydrated at read time by `ProductDomainFactory` using `CustomFieldDefinitionRegistry`.

**Variations Table Schema**:
```
shopwired.product_variations
├── id (UUID primary)
├── product_id (UUID FK → products.id, cascade delete)
├── product_external_id (int) - stable sync key
├── external_id (int) - ShopWired variation ID
├── UNIQUE(product_external_id, external_id)
├── sku, price, cost_price, sale_price, stock, weight
├── gtin, mpn, image_url
├── options (JSONB array of {option_id, option_name, value_id, value_name})
└── created_at, updated_at
```

### Phase 3: Application Contracts

| File | Description |
|------|-------------|
| `app/Application/Contracts/Shopwired/ProductClientInterface.php` | API client contract |
| `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` | Repository contract extending ShopwiredRepositoryInterface |

**ProductClientInterface Methods**:
- `listAllProducts(): array` - All products with embeds (loads all into memory)
- `iterateProductBatches(): Generator<int, list<Product>>` - Yields batches, key=page number
- `getProductById(int $id): Product` - Single product fetch
- `getProductCount(): int` - Total count
- `getAllProductIds(): array` - Lightweight fetch of all external_ids only (for reconciliation)

**Note**: No `ProductSort` enum needed - only `title`/`title_desc` available, not useful for sync ordering.

**ProductRepositoryInterface Methods** (extends ShopwiredRepositoryInterface):
- Inherits: `save()`, `saveMany()`, `getByExternalId()`, `existsByExternalId()`
- `getAllExternalIds(): array` - All local product external_ids (for reconciliation)
- `deleteByExternalIds(array $externalIds): int` - Delete orphans, returns count deleted

Additional query methods (getBySku, getByCategory, etc.) deferred until specific requirements are clearer.

### Phase 4: Infrastructure DTOs & Factory

#### Phase 4a: Response DTOs

| File | Description |
|------|-------------|
| `app/Infrastructure/Shopwired/Responses/ProductImageResponse.php` | Image DTO (simple, can have toDomain()) |
| `app/Infrastructure/Shopwired/Responses/ProductVariationOptionResponse.php` | Option DTO (simple, can have toDomain()) |
| `app/Infrastructure/Shopwired/Responses/ProductVariationResponse.php` | Variation DTO (simple, can have toDomain()) |
| `app/Infrastructure/Shopwired/Responses/ProductResponse.php` | Product DTO - **NO toDomain()**, raw customFields |

All use `#[MapInputName(SnakeCaseMapper::class)]`.

**Important**: `ProductResponse` does NOT implement `DomainConvertibleInterface`. It holds raw `customFields: array<string, mixed>` from API. Transformation to Domain happens via `ProductDomainFactory`.

#### Phase 4b: Custom Field Infrastructure

| File | Description |
|------|-------------|
| `app/Infrastructure/Shopwired/CustomFields/CustomFieldDefinitionRegistry.php` | In-memory lookup keyed by field name, scoped to item type |
| `app/Infrastructure/Shopwired/Factories/ProductDomainFactory.php` | Transforms ProductResponse → Product with typed CustomFieldValue[] |

**CustomFieldDefinitionRegistry**:
```php
final readonly class CustomFieldDefinitionRegistry
{
    /** @var array<string, CustomFieldDefinition> */
    private array $byName;

    public static function forItemType(array $definitions, CustomFieldItemType $itemType): self;
    public function findByName(string $name): ?CustomFieldDefinition;
    public function has(string $name): bool;
}
```

**ProductDomainFactory**:
```php
final class ProductDomainFactory
{
    private ?CustomFieldDefinitionRegistry $registry = null;

    public function __construct(
        private CustomFieldRepositoryInterface $customFieldRepo,
    ) {}

    public function fromResponse(ProductResponse $response): Product
    {
        return new Product(
            // ... map all fields ...
            customFields: $this->buildCustomFields($response->customFields),
        );
    }

    private function registry(): CustomFieldDefinitionRegistry
    {
        // Lazy-load once per factory instance
        return $this->registry ??= CustomFieldDefinitionRegistry::forItemType(
            $this->customFieldRepo->getByItemType(CustomFieldItemType::Product),
            CustomFieldItemType::Product,
        );
    }

    /** @return list<CustomFieldValue> */
    private function buildCustomFields(array $rawFields): array
    {
        $result = [];
        foreach ($rawFields as $name => $value) {
            $definition = $this->registry()->findByName($name);
            if ($definition !== null) {
                $result[] = $this->createTypedValue($definition, $value);
            }
        }
        return $result;
    }
}
```

### Phase 5: Infrastructure Persistence

| File | Description |
|------|-------------|
| `app/Infrastructure/Shopwired/Models/ProductModel.php` | Eloquent model with HasMany variations |
| `app/Infrastructure/Shopwired/Models/ProductVariationModel.php` | Child model with AutoDomainMappingTrait |
| `app/Infrastructure/Shopwired/Mappers/ProductModelMapper.php` | Complex mapping for nested relations |
| `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` | Extends AbstractShopwiredEloquentRepository |

**Variation Sync Strategy** (like order_products):
1. Delete existing variations by `product_external_id`
2. Insert new variations in transaction
3. Use composite unique for idempotency

### Phase 6: Infrastructure Client

| File | Description |
|------|-------------|
| `app/Infrastructure/Shopwired/Clients/ProductClient.php` | API client using ShopwiredHttpTransport + ProductDomainFactory |

**API Configuration**:
- Endpoint: `products`
- Embeds: `variations,images,categories,custom_fields` ← custom_fields added
- Fields: Must include `customFields` (camelCase) when `custom_fields` embed is used
- Page size: 100 (max)
- Sort: Not specified (API only supports title sort, not useful for sync)
- Uses `ShopwiredPaginator::pages()` for generator-based iteration

**Client Pattern** (differs from Customer/Order due to factory):
```php
final readonly class ProductClient implements ProductClientInterface
{
    public function __construct(
        private ShopwiredHttpTransport $transport,
        private ProductDomainFactory $factory,  // ← Injected factory
    ) {}

    public function iterateProductBatches(): Generator
    {
        // ... pagination logic ...
        foreach ($response->json() as $item) {
            $products[] = $this->factory->fromResponse(
                ProductResponse::from($item)
            );
        }
        yield $products;
    }
}
```

**Note**: Unlike CustomerClient/OrderClient which use `DTO.toDomain()`, ProductClient uses the injected factory because custom field transformation requires the registry.

### Phase 7: Application Use Case

| File | Description |
|------|-------------|
| `app/Application/Shopwired/UseCases/SyncProductsUseCase.php` | Orchestration following SyncCustomersUseCase pattern |

**Configuration**:
- `PAGES_PER_BATCH = 10` (~1000 products per flush)
- `PROGRESS_LOG_INTERVAL = 5` (log every 5 batches)

### Phase 8: Presentation Job

| File | Description |
|------|-------------|
| `app/Presentation/Jobs/SyncShopwiredProductsJob.php` | Queue job with retry handling |

**Job Configuration**:
```php
$tries = 5;
$timeout = 900;  // 15 minutes (small catalog)
$backoff = [60, 120, 300, 600, 1200];
$queue = 'low';
```

**Sync Mode**: Full sync only (no quick/micro modes)
- ShopWired Products API only supports `title`/`title_desc` sorting
- No `created_at` or `updated_at` sort available, so incremental sync not viable
- Full sync of ~1,500 products takes ~2-5 minutes, acceptable for daily schedule

### Phase 9: Service Provider Wiring

| File | Changes |
|------|---------|
| `app/Infrastructure/Shopwired/ShopwiredClientFactory.php` | Add `createProductClient()` method |
| `app/Providers/ShopwiredServiceProvider.php` | Register all bindings (see below) |

**Service Provider Bindings**:
```php
// ProductDomainFactory - MUST be scoped() for Octane compatibility
// Fresh instance per request/job ensures registry is not stale
$this->app->scoped(ProductDomainFactory::class);

// Client and Repository - standard bindings
$this->app->bind(ProductClientInterface::class, function ($app) {
    return $app->make(ShopwiredClientFactory::class)->createProductClient();
});

$this->app->bind(ProductRepositoryInterface::class, EloquentProductRepository::class);
```

**Critical**: `ProductDomainFactory` uses `scoped()` NOT `singleton()`. This ensures:
- Fresh factory instance per queue job
- Registry lazy-loaded fresh each job
- No stale custom field definitions in Octane long-running process

### Phase 10: Reconciliation Job (Handles Deleted Products)

| File | Description |
|------|-------------|
| `app/Presentation/Jobs/ReconcileShopwiredProductsJob.php` | Removes orphaned products no longer in ShopWired |

**Why needed**: SKUs are not unique. When products are deleted in ShopWired and recreated with the same SKU, orphaned local records cause data conflicts.

**Job Configuration**:
```php
$tries = 3;
$timeout = 300;  // 5 minutes (lightweight ID comparison)
$backoff = [60, 120, 300];
$queue = 'low';
```

**Algorithm**:
1. Fetch all product external_ids from ShopWired API (lightweight - IDs only)
2. Query local DB for all product external_ids
3. Find orphans: `local_ids - api_ids`
4. Delete orphaned products (cascade deletes variations)
5. Log all deletions at INFO level for audit trail

**Schedule**: Daily overnight (after main sync completes)

---

## Key Design Decisions

1. **Images as JSONB** - No separate `product_images` table. Store as JSONB array on products table. Simpler, no joins needed, images always fetched with product.

2. **Category IDs only** - Store `category_ids` as JSONB array. Use existing `CategoryClient` for full category details when needed. Later: implement category sync + Eloquent relationship enrichment.

3. **Dual ID system** - UUID `id` for internal FK relationships, `external_id` for ShopWired sync key. Child tables have both `product_id` (UUID) and `product_external_id` (stable sync key).

4. **Variation sync via delete+insert** - On product update, delete all variations by `product_external_id`, then insert new ones. Simpler than diffing, idempotent.

5. **Separate reconciliation job** - Products deleted from ShopWired are removed by a daily reconciliation job (not during main sync). This prevents accidental mass deletion if API fails mid-sync, and handles the SKU reuse scenario where deleted products are recreated with the same SKU.

6. **Custom fields stored as raw JSONB, typed on read** - Raw `custom_fields` JSONB in database matches API response. `ProductDomainFactory` hydrates to typed `CustomFieldValue` objects using `CustomFieldDefinitionRegistry`. This decouples storage from domain typing.

7. **Generic CustomFieldValue hierarchy** - Abstract `CustomFieldValue` with typed subtypes (`StringCustomFieldValue`, `ToggleCustomFieldValue`, etc.) is entity-agnostic. Can be reused for Products, Customers, Orders without duplication.

8. **Factory with lazy-loaded registry** - `ProductDomainFactory` lazy-loads `CustomFieldDefinitionRegistry` on first use. Registered with `scoped()` binding ensures fresh registry per queue job, avoiding Octane stale state.

9. **Combined value objects** - `CustomFieldValue` subtypes embed the full `CustomFieldDefinition`, not just a reference. This provides complete context (label, type, allowedValues) without additional lookups.

10. **Strict custom field validation** - Domain/Infrastructure throws exceptions for custom field errors (unknown field name, type mismatch). Application layer decides policy: fail sync entirely, or catch per-product and log+continue. This follows Clean Architecture's principle that inner layers are strict, outer layers set policy.

---

## File Dependencies (Implementation Order)

```
Phase 1a: Custom Field Value Objects
1. CustomFieldValue (abstract base, depends on CustomFieldDefinition)
2. StringCustomFieldValue, ToggleCustomFieldValue, ValueListCustomFieldValue, ProductListCustomFieldValue

Phase 1b: Product Domain (existing, needs update)
3. ProductImage, ProductVariationOption (✅ exist, no changes)
4. ProductVariation (✅ exists, no changes)
5. Product (⚠️ exists, ADD customFields property)

Phase 2: Database
6. Migrations (no code deps)

Phase 3: Application Contracts
7. ProductClientInterface, ProductRepositoryInterface (depend on Product)

Phase 4a: Response DTOs
8. ProductImageResponse, ProductVariationOptionResponse (no deps)
9. ProductVariationResponse (depends on ProductVariationOptionResponse)
10. ProductResponse (depends on all Response DTOs, NO toDomain())

Phase 4b: Custom Field Infrastructure
11. CustomFieldDefinitionRegistry (depends on CustomFieldDefinition)
12. ProductDomainFactory (depends on Registry, ProductResponse, all CustomFieldValue subtypes)

Phase 5: Persistence
13. ProductVariationModel (depends on domain objects)
14. ProductModel (depends on ProductVariationModel)
15. ProductModelMapper (depends on models + domain)
16. EloquentProductRepository (depends on mapper, models)

Phase 6: Client
17. ProductClient (depends on DTOs, transport, ProductDomainFactory)

Phase 7-8: Application + Presentation
18. SyncProductsUseCase (depends on interfaces)
19. SyncShopwiredProductsJob (depends on use case)
20. ReconcileShopwiredProductsJob (depends on interfaces - can be done after main sync works)

Phase 9: Wiring
21. ShopwiredServiceProvider updates (wires everything, scoped() for factory)
```

---

## Verification Plan

### Unit Tests
- [ ] `Product::hasVariations()` returns correct boolean
- [ ] `Product::totalStock()` sums variations or returns master stock
- [ ] `ProductVariationResponse::toDomain()` handles nested options
- [ ] `CustomFieldDefinitionRegistry::findByName()` returns correct definition
- [ ] `CustomFieldDefinitionRegistry::forItemType()` filters by item type
- [ ] `StringCustomFieldValue` holds string value with embedded definition
- [ ] `ToggleCustomFieldValue` holds boolean value
- [ ] `ValueListCustomFieldValue` holds array of strings
- [ ] `ProductListCustomFieldValue` holds array of ints

### Integration Tests
- [ ] `EloquentProductRepository::save()` creates product with variations
- [ ] `EloquentProductRepository::save()` updates existing (upsert)
- [ ] `EloquentProductRepository::getByCategory()` JSONB contains works
- [ ] `ProductClient::iterateProductBatches()` pagination works (mocked transport)
- [ ] `ProductDomainFactory::fromResponse()` creates typed custom field values
- [ ] `ProductDomainFactory` throws `CustomFieldNotFoundException` for unknown field names
- [ ] `ProductDomainFactory` lazy-loads registry only once per instance

### End-to-End Tests
- [ ] Run `SyncShopwiredProductsJob` against real API (staging/dev)
- [ ] Verify products appear in database with variations
- [ ] Verify re-run is idempotent (no duplicates)
- [ ] Verify error handling (auth failure, API unavailable)
- [ ] Run `ReconcileShopwiredProductsJob` - verify orphan deletion works
- [ ] Verify cascade delete removes variations when product deleted

### Manual Verification
```bash
# Run migration
php artisan migrate

# Test job manually (full sync)
php artisan tinker
>>> SyncShopwiredProductsJob::dispatchSync()

# Verify data
>>> ProductModel::count()
>>> ProductModel::first()->variations
>>> ProductModel::where('is_active', true)->count()
```

---

## Critical Reference Files

- `app/Application/Shopwired/UseCases/SyncCustomersUseCase.php` - UseCase pattern
- `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php` - Child sync pattern
- `app/Infrastructure/Shopwired/Clients/CustomerClient.php` - Client pagination pattern (note: Products uses factory, differs slightly)
- `database/migrations/2026_01_11_033929_create_shopwired_order_products_table.php` - Child table migration
- `app/Providers/ShopwiredServiceProvider.php` - Binding registration
- `app/Domain/Catalog/CustomFields/ValueObjects/CustomFieldDefinition.php` - Existing custom field definition VO
- `app/Domain/Catalog/CustomFields/Enums/CustomFieldType.php` - Field type enum (Text, Toggle, Choice, etc.)
- `app/Infrastructure/Shopwired/Repositories/EloquentCustomFieldRepository.php` - For loading definitions into registry
