# ShopWired Product Sync Implementation Plan

## Overview

Implement full product synchronization from ShopWired API to local database, following the established patterns from `SyncShopwiredOrdersJob` and `SyncShopwiredCustomersJob`.

**Scope**: Standard (core fields + pricing + variations + images)
**Catalog Size**: ~1,000-1,500 products
**Estimated Sync Time**: ~2-5 minutes full sync

---

## Architecture Summary

```
Job (SyncShopwiredProductsJob)
  → UseCase (SyncProductsUseCase)
    → Client (ProductClient via ProductClientInterface)
    → Repository (EloquentProductRepository via ProductRepositoryInterface)
      → Models (ProductModel, ProductVariationModel)
        → Database (shopwired.products, shopwired.product_variations)
```

---

## Implementation Phases

### Phase 1: Domain Layer

| File | Description |
|------|-------------|
| `app/Domain/Product/ValueObjects/ProductImage.php` | Image with id, url, description, sortOrder |
| `app/Domain/Product/ValueObjects/ProductVariationOption.php` | Option attribute (optionId, optionName, valueId, valueName) |
| `app/Domain/Product/ValueObjects/ProductVariation.php` | Variation with pricing, stock, options array |
| `app/Domain/Product/ValueObjects/Product.php` | Main product with all fields, variations[], images[], categoryIds[] |

**Key Properties (Product)**:
- Identifiers: `id`, `sku`, `slug`, `url`
- Pricing: `price`, `costPrice`, `salePrice`, `comparePrice`
- Inventory: `stock`, `outOfStockStatus`
- Flags: `isActive`, `isNew`, `isPreOrder`, `vatExclusive`, `vatRelief`, `isBundle`
- Shipping: `weight`, `deliveryPrice`, `freeDelivery`
- SEO: `metaTitle`, `metaDescription`
- Relations: `categoryIds[]` (JSONB), `variations[]`, `images[]` (JSONB)
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
├── stock, out_of_stock_status
├── is_active, is_new, is_pre_order, vat_exclusive, vat_relief, is_bundle
├── weight, delivery_price, free_delivery
├── meta_title, meta_description
├── category_ids (JSONB array of ints)
├── images (JSONB array of {id, url, description, sort_order})
├── shopwired_created_at, shopwired_updated_at
└── created_at, updated_at
```

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

### Phase 4: Infrastructure DTOs

| File | Description |
|------|-------------|
| `app/Infrastructure/Shopwired/Responses/ProductImageResponse.php` | Image DTO with toDomain() |
| `app/Infrastructure/Shopwired/Responses/ProductVariationOptionResponse.php` | Option DTO |
| `app/Infrastructure/Shopwired/Responses/ProductVariationResponse.php` | Variation DTO |
| `app/Infrastructure/Shopwired/Responses/ProductResponse.php` | Product DTO with nested collections |

All use `#[MapInputName(SnakeCaseMapper::class)]` and implement `DomainConvertibleInterface`.

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
| `app/Infrastructure/Shopwired/Clients/ProductClient.php` | API client using ShopwiredHttpTransport |

**API Configuration**:
- Endpoint: `products`
- Embeds: `variations,images,categories`
- Page size: 100 (max)
- Sort: Not specified (API only supports title sort, not useful for sync)
- Uses `ShopwiredPaginator::pages()` for generator-based iteration

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
| `app/Providers/ShopwiredServiceProvider.php` | Register ProductClientInterface and ProductRepositoryInterface bindings |

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

---

## File Dependencies (Implementation Order)

```
1. ProductImage, ProductVariationOption (no deps)
2. ProductVariation (depends on ProductVariationOption)
3. Product (depends on ProductVariation, ProductImage)
4. Migrations (no code deps)
5. ProductClientInterface, ProductRepositoryInterface (depend on Product)
6. ProductImageResponse, ProductVariationOptionResponse (no deps)
7. ProductVariationResponse (depends on ProductVariationOptionResponse)
8. ProductResponse (depends on all Response DTOs)
9. ProductVariationModel (depends on domain objects)
10. ProductModel (depends on ProductVariationModel)
11. ProductModelMapper (depends on models + domain)
12. EloquentProductRepository (depends on mapper, models)
13. ProductClient (depends on DTOs, transport)
14. SyncProductsUseCase (depends on interfaces)
15. SyncShopwiredProductsJob (depends on use case)
16. ReconcileShopwiredProductsJob (depends on interfaces - can be done after main sync works)
17. ShopwiredServiceProvider updates (wires everything)
```

---

## Verification Plan

### Unit Tests
- [ ] `Product::hasVariations()` returns correct boolean
- [ ] `Product::totalStock()` sums variations or returns master stock
- [ ] `ProductResponse::toDomain()` maps all fields correctly
- [ ] `ProductVariationResponse::toDomain()` handles nested options

### Integration Tests
- [ ] `EloquentProductRepository::save()` creates product with variations
- [ ] `EloquentProductRepository::save()` updates existing (upsert)
- [ ] `EloquentProductRepository::getByCategory()` JSONB contains works
- [ ] `ProductClient::iterateProductBatches()` pagination works (mocked transport)

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
- `app/Infrastructure/Shopwired/Clients/CustomerClient.php` - Client pagination pattern
- `database/migrations/2026_01_11_033929_create_shopwired_order_products_table.php` - Child table migration
- `app/Providers/ShopwiredServiceProvider.php` - Binding registration
