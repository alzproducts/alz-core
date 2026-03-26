# Plan: Consumer API Expansion — Categories, Brands, Filter Groups, Orders, Customers

## Context

The consumer API currently exposes only Products (`GET /api/products`, `GET /api/products/{id}`). ShopWired data is synced to our local database via webhooks and bulk sync jobs, but Categories, Brands, Filter Groups, Orders, and Customers are only accessible internally. The frontend needs these endpoints to build catalog navigation, order management, and customer views — all reads go through the local Postgres database, not the ShopWired API.

**Goal:** Replicate the Products API pattern for 5 additional entities, following the same Clean Architecture stack: Domain View VO → Repository interface/impl → UseCase → Request DTO → Resource → Controller → Route.

---

## Phase 1: Categories API

The simplest entity to port — mirrors Products closely but without computed fields.

### Domain Layer

**Move existing VOs to own subdirectory:**
- `app/Domain/Catalog/ValueObjects/Category.php` → `app/Domain/Catalog/Category/ValueObjects/Category.php`
- `app/Domain/Catalog/ValueObjects/CategoryImage.php` → `app/Domain/Catalog/Category/ValueObjects/CategoryImage.php`

**Create `app/Domain/Catalog/Category/ValueObjects/CategoryView.php`:**
- `IntId $id` (external ID — matches ProductView pattern)
- `string $title`, `$slug`, `$url`
- `bool $active`, `$featured`, `$tradeOnly`
- `int $sortOrder`
- `?string $metaTitle`, `$metaDescription`, `$metaKeywords`
- `bool $metaNoIndex`
- `?CategoryImage $image`
- `DateTimeImmutable $createdAt`
- Conditional includes (null = not loaded):
  - `?string $description`, `$description2`
  - `?list<IntId> $parentIds` (domain-typed, not raw ints)
  - `?array<string, mixed> $customFields`

### Application Layer

**`app/Application/Contracts/Shopwired/CategoryRepositoryInterface.php`** — add methods:
- `paginate(int $perPage, int $page, array $includes = [], bool $includeInactive = false): PaginatedListDTO<CategoryView>`
- `findCategoryForApi(IntId $categoryId, array $includes = []): CategoryView` — returns any category regardless of active status

**New files:**
- `app/Application/Catalog/UseCases/ListCategoriesUseCase.php` — inject `CategoryRepositoryInterface`, delegates to `paginate()`
- `app/Application/Catalog/UseCases/GetCategoryUseCase.php` — inject `CategoryRepositoryInterface`, delegates to `findCategoryForApi()`
- `app/Application/Catalog/UseCases/GetCategoryResult.php` — wraps `CategoryView` + `$includes` (same pattern as `GetProductResult`)

### Infrastructure Layer

**`app/Infrastructure/Shopwired/Repositories/EloquentCategoryRepository.php`** — implement `paginate()` and `findCategoryForApi()`:
- Query `CategoryModel` ordered by `sort_order`; filter `active = true` unless `includeInactive` is set
- `findCategoryForApi()` does NOT filter by active — returns any category (404 only if ID doesn't exist)
- Map via `CategoryModel::toDomain()` then convert to `CategoryView`
- Conditional loading of description, description2, parentIds, customFields based on `$includes`

**Update `app/Infrastructure/Shopwired/Models/CategoryModel.php`** — add `toViewDomain(array $includes)` method returning `CategoryView`

### Presentation Layer

**New files:**
- `app/Presentation/Http/Api/Controllers/CategoryController.php` — `index()` + `show()`, same pattern as `ProductController`
- `app/Presentation/Http/Api/Resources/CategoryResource.php` — list view (omits description, customFields)
- `app/Presentation/Http/Api/Resources/CategoryDetailResource.php` — show view with conditional embeds
- `app/Presentation/Http/Api/DTOs/ListCategoriesRequestDTO.php` — pagination + `include` validation + `include_inactive` boolean filter (default false)
- `app/Presentation/Http/Api/DTOs/ShowCategoryRequestDTO.php` — `include` validation

**Allowed includes:**
- List: (none initially — categories are simple enough)
- Show: `description`, `description2`, `parent_ids`, `custom_fields`

**Routes (`routes/api.php`):**
```php
Route::get('categories', [CategoryController::class, 'index']);
Route::get('categories/{categoryId}', [CategoryController::class, 'show'])->whereNumber('categoryId');
```

### Update all imports

After moving `Category.php` and `CategoryImage.php`, update all files that import them (sync use cases, webhook controllers, jobs, tests, repository interfaces, etc.).

---

## Phase 2: Brands API

Nearly identical to Categories but simpler — no `parentIds`, `tradeOnly`, `description2`, or `metaNoIndex`.

### Domain Layer

**Move existing VOs:**
- `app/Domain/Catalog/ValueObjects/Brand.php` → `app/Domain/Catalog/Brand/ValueObjects/Brand.php`
- `app/Domain/Catalog/ValueObjects/BrandImage.php` → `app/Domain/Catalog/Brand/ValueObjects/BrandImage.php`

**Create `app/Domain/Catalog/Brand/ValueObjects/BrandView.php`:**
- `IntId $id`
- `string $title`, `$slug`, `$url`
- `bool $active`, `$featured`
- `int $sortOrder`
- `?string $metaTitle`, `$metaDescription`, `$metaKeywords`
- `?BrandImage $image`
- `DateTimeImmutable $createdAt`
- Conditional: `?string $description`, `?array<string, mixed> $customFields`

### Application / Infrastructure / Presentation

Same stack as Phase 1, substituting Brand for Category throughout. Includes the same `include_inactive` filter on the list endpoint and show returns any brand regardless of active status.

**Allowed includes (show):** `description`, `custom_fields`

**Routes:**
```php
Route::get('brands', [BrandController::class, 'index']);
Route::get('brands/{brandId}', [BrandController::class, 'show'])->whereNumber('brandId');
```

---

## Phase 3: Filter Groups API

Simplest entity — 4 fields, no includes, no detail endpoint needed.

### Domain Layer

No new VO needed — `FilterGroupDefinition` is already simple enough to serve as the API projection. No `FilterGroupView` required (only 4 fields: id, title, optionNo, sortOrder).

### Application Layer

- `app/Application/Catalog/UseCases/ListFilterGroupsUseCase.php` — delegates to `FilterGroupRepositoryInterface::paginate()`

**No show endpoint** — filter groups are small metadata; list-all is sufficient.

### Application Contracts

**`FilterGroupRepositoryInterface`** — add:
- `paginate(int $perPage, int $page): PaginatedListDTO<FilterGroupDefinition>`

### Infrastructure

**`EloquentFilterGroupRepository`** — implement `paginate()`

### Presentation

- `app/Presentation/Http/Api/Controllers/FilterGroupController.php` — `index()` only
- `app/Presentation/Http/Api/Resources/FilterGroupResource.php` — maps id, title, option_no, sort_order
- `app/Presentation/Http/Api/DTOs/ListFilterGroupsRequestDTO.php` — pagination only (no includes)

**Route:**
```php
Route::get('filter-groups', [FilterGroupController::class, 'index']);
```

---

## Phase 4: Orders API

Most complex entity — nested relations, deduplicated view, date range filtering.

### Domain Layer

OrderView is our own internal projection — it does NOT mirror ShopWired's entity/embed structure. Fields are culled to what the frontend needs, monetary values use domain types, and nested ShopWired-specific VOs are either flattened or dropped.

**Create `app/Domain/Catalog/Order/ValueObjects/OrderView.php`:**

Base fields (always returned):

| Field | Type | Notes |
|-------|------|-------|
| `id` | `IntId` | ShopWired external ID |
| `reference` | `int` | Customer-facing order number (not an entity ID) |
| `alzReference` | `string` | `'A' . $reference` — ALZ-prefixed reference |
| `customerId` | `IntId` | For cross-ref via Customers API (replaces embedded OrderCustomer) |
| `orderPlacedAt` | `DateTimeImmutable` | |
| `total` | `Money::inclusive()` | Gross order total incl VAT |
| `shippingCharge` | `Money::exclusive()` | Shipping cost excl VAT |
| `orderTaxRate` | `TaxRate` | VAT rate on order items |
| `shippingTaxRate` | `TaxRate` | VAT rate on shipping |
| `preOrderStatus` | `PreOrderStatus` | None / Partial / Full |
| `hasVatRelief` | `bool` | |
| `isArchived` | `bool` | |
| `customerReferenceNumber` | `?string` | Extracted from comments |
| `comments` | `?string` | Raw order comments |
| `originalDeliveryDate` | `?DateTimeImmutable` | |

Conditional includes (null = not loaded):

| Include key | Type | Notes |
|------------|------|-------|
| `addresses` | `OrderAddress` × 2 | `billingAddress` + `shippingAddress` (existing VO, order-time snapshots) |
| `products` | `list<OrderProductView>` | New simplified VO — see below |
| `admin_comments` | `list<OrderAdminComment>` | Existing VO |
| `invoice_url` | `?string` | Invoice download link |

**Dropped entirely:** `OrderCustomer` (replaced by `customerId`), `OrderShipping` (charge captured in `shippingCharge`), `OrderDiscount` (not used), `OrderRefund` (design properly when needed), `OrderStatus` wrapper (just enum), `PaymentMethod` (design properly when needed), `status` (design properly when needed), `trackingUrl`, `marketing`, `isAnonymized`, `lineItemVatCalculation`, `transactionId`, `customFields`, `subTotalNet` (derivable), `originalShippingTotalNet`, `taxValue` (derivable)

---

**Create `app/Domain/Catalog/Order/ValueObjects/OrderProductView.php`:**

Simplified read-only projection of an order line item with domain types:

| Field | Type | Notes |
|-------|------|-------|
| `productId` | `IntId` | ShopWired product ID |
| `title` | `string` | Product title at time of purchase |
| `sku` | `Sku` | Domain-typed SKU |
| `unitPrice` | `Money::exclusive()` | Net price per unit |
| `quantity` | `int` | |
| `lineTotal` | `Money::exclusive()` | Net total for line |
| `vatRate` | `TaxRate` | Line-level VAT rate |
| `isPreorder` | `bool` | |
| `preorderDate` | `?DateTimeImmutable` | |
| `variation` | `list<ProductVariation>` | Option selections (e.g. Color: Red) |

**Dropped from OrderProduct:** `orderExternalId` (redundant), `priceVat`/`totalVat` (derivable from price + vatRate), `originalPrice` (ShopWired internal), `costPrice` (not for order view), `comments` (parsed into `isPreorder`), `customFields` (ShopWired-specific)

### Application Layer

**`OrderRepositoryInterface`** — add:
- `paginate(int $perPage, int $page, array $includes = [], ?DateTimeImmutable $dateFrom = null, ?DateTimeImmutable $dateTo = null): PaginatedListDTO<OrderView>`
- `findOrderForApi(IntId $orderId, array $includes = []): OrderView`

**New use cases:**
- `app/Application/Catalog/UseCases/ListOrdersUseCase.php`
- `app/Application/Catalog/UseCases/GetOrderUseCase.php`
- `app/Application/Catalog/UseCases/GetOrderResult.php`

### Infrastructure

**`EloquentOrderRepository`** — implement `paginate()` and `findOrderForApi()`:
- Query from `shopwired.orders_deduplicated` view (deduplication built-in)
- Conditional eager loading of products (from `order_products_resolved` view), adminComments
- Map to `OrderView` via mapper

### Presentation

- `app/Presentation/Http/Api/Controllers/OrderController.php` — `index()` + `show()`
- `app/Presentation/Http/Api/Resources/OrderResource.php` — list view (header fields, status, customer name, totals)
- `app/Presentation/Http/Api/Resources/OrderDetailResource.php` — show with conditional embeds
- Sub-resources: `OrderProductViewResource`, `OrderAdminCommentResource`, `OrderAddressResource`
- `app/Presentation/Http/Api/DTOs/ListOrdersRequestDTO.php` — pagination + date filtering (`date_from`, `date_to` as optional ISO 8601 date params)
- `app/Presentation/Http/Api/DTOs/ShowOrderRequestDTO.php` — includes

**Allowed includes:**
- List: (none — order headers only in list)
- Show: `addresses`, `products`, `admin_comments`, `invoice_url`

**Default sort:** `order_placed_at DESC` (newest first)

**Routes:**
```php
Route::get('orders', [OrderController::class, 'index']);
Route::get('orders/{orderId}', [OrderController::class, 'show'])->whereNumber('orderId');
```

---

## Phase 5: Customers API

### Domain Layer

**Create `app/Domain/Customer/ValueObjects/CustomerView.php`:**
- `IntId $id`
- `DateTimeImmutable $createdAt`
- `string $email`, `$firstName`, `$lastName`
- `?string $companyName`
- `bool $isTrade`, `$isActive`, `$acceptsMarketing`
- `?bool $isCreditEnabled`
- Conditional includes (null = not loaded):
  - `?string $phone`, `$mobilePhone` — **detail only** (PII, not in list view)
  - `?string $notes`
  - `?CustomerAddress $address`
  - `?array<string, mixed> $customFields`

### Application Layer

**`CustomerRepositoryInterface`** — add:
- `paginate(int $perPage, int $page, array $includes = []): PaginatedListDTO<CustomerView>`
- `findCustomerForApi(IntId $customerId, array $includes = []): CustomerView`

**New use cases:**
- `app/Application/Customer/UseCases/ListCustomersUseCase.php`
- `app/Application/Customer/UseCases/GetCustomerUseCase.php`
- `app/Application/Customer/UseCases/GetCustomerResult.php`

### Infrastructure

**`app/Infrastructure/Shopwired/Repositories/EloquentCustomerRepository.php`** — implement `paginate()` and `findCustomerForApi()`:
- Query `CustomerModel` ordered by `created_at DESC`
- Map to `CustomerView` via model method or mapper
- Conditional loading of phone, address, notes, customFields based on `$includes`

### Presentation

- `app/Presentation/Http/Api/Controllers/CustomerController.php` — `index()` + `show()`
- `app/Presentation/Http/Api/Resources/CustomerResource.php` — list view
- `app/Presentation/Http/Api/Resources/CustomerDetailResource.php` — show with embeds
- `app/Presentation/Http/Api/Resources/CustomerAddressResource.php` — nested address
- DTOs for list/show request validation

**Allowed includes (show):** `phone`, `address`, `custom_fields`, `notes`

**Default sort:** `created_at DESC` (newest first)

**Routes:**
```php
Route::get('customers', [CustomerController::class, 'index']);
Route::get('customers/{customerId}', [CustomerController::class, 'show'])->whereNumber('customerId');
```

---

## Cross-cutting Concerns

### Domain Type Usage — Mandatory for All New Code

**All new View VOs, resources, and mappers MUST use native domain types instead of primitives.** Do NOT introduce raw `float` for money, `int` for entity IDs, or `string` for SKUs. Existing VOs (Category, Brand, Order, OrderProduct, etc.) are NOT modified — they keep their current primitive types to avoid knock-on effects.

**Type reference for new code:**

| Concept | Domain Type | NOT |
|---------|------------|-----|
| Entity/external IDs | `IntId` | `int` |
| UUID identifiers | `Guid` | `string` |
| All monetary values | `Money` | `float` |
| Product identifiers | `Sku` | `string` |
| Barcodes | `Gtin` | `string` |
| Weight measurements | `Weight` | `float` |
| Physical dimensions | `Dimensions` | `float` |
| Tax treatment | `TaxType` | `string`/`bool` |
| Tax rate | `TaxRate` | `float` |
| Date ranges | `DateRange` | two `DateTimeImmutable` |
| Category parent refs | `list<IntId>` | `list<int>` |

**Key namespaces:**
- `App\Domain\ValueObjects\IntId`, `Guid`
- `App\Domain\Shared\Money\ValueObjects\Money`
- `App\Domain\Inventory\ValueObjects\Weight`, `Dimensions`
- `App\Domain\Catalog\Product\ValueObjects\Sku`, `Gtin`
- `App\Domain\ValueObjects\TaxType`, `TaxRate`, `DateRange`

**When mapping from Eloquent models to View VOs:** The mapper/`toViewDomain()` method is responsible for converting database primitives (e.g., `decimal` column → `Money`, `integer` column → `IntId`). This is the same pattern `ProductModelMapper::toViewDomain()` uses.

### Update `app/Domain/CLAUDE.md`

Add a **Native Domain Types** reference section to the Domain layer CLAUDE.md so future development uses domain types by default. This codifies the types table above as a permanent project guideline.

**Add to `app/Domain/CLAUDE.md`:**

```markdown
## Native Domain Types

All new domain code MUST use native domain types instead of primitives. Existing VOs are NOT retroactively updated unless explicitly scoped.

| Concept | Domain Type | Namespace | NOT |
|---------|------------|-----------|-----|
| Entity/external IDs | `IntId` | `App\Domain\ValueObjects` | `int` |
| UUID identifiers | `Guid` | `App\Domain\ValueObjects` | `string` |
| Monetary values | `Money` | `App\Domain\Shared\Money\ValueObjects` | `float` |
| Product identifiers | `Sku` | `App\Domain\Catalog\Product\ValueObjects` | `string` |
| Barcodes | `Gtin` | `App\Domain\Catalog\Product\ValueObjects` | `string` |
| Weight | `Weight` | `App\Domain\Inventory\ValueObjects` | `float` |
| Dimensions | `Dimensions` | `App\Domain\Inventory\ValueObjects` | `float` |
| Tax treatment | `TaxType` | `App\Domain\ValueObjects` | `string`/`bool` |
| Tax rate | `TaxRate` | `App\Domain\ValueObjects` | `float` |
| Date ranges | `DateRange` | `App\Domain\ValueObjects` | two `DateTimeImmutable` |

### Money Tax Type Selection

When creating `Money` instances, the tax type must be explicitly chosen:

| Scenario | Constructor | Example |
|----------|-----------|---------|
| Customer-facing prices (incl VAT) | `Money::inclusive()` | Order total, sale price, refund value |
| Trade/cost/net prices (excl VAT) | `Money::exclusive()` | Cost price, subtotal net, shipping net |
| Tax amounts themselves | `Money::zeroRated()` | VAT value, priceVat, totalVat |
| Items exempt from VAT | `Money::zeroRated()` | Zero-rated goods (books, children's clothing) |
| Nullable "not set" amounts | `Money::nonZeroOrNull()` | Optional cost price, sale price |
```

### Auth & Middleware
All new endpoints use the same Consumer API route group: `ValidateSupabaseJwtMiddleware` + `EnsureUserApprovedMiddleware` + `throttle:api`.

### Error Handling
Existing `InternalApiExceptionMapper` already handles `ResourceNotFoundException` (404), `DatabaseOperationFailedException` (500), `ExternalServiceUnavailableException` (503). No changes needed.

### Reusable Components (already exist)
- `BuildsPaginatedResponseTrait` — all list controllers
- `ValidatesIncludesTrait` — all request DTOs with includes
- `PaginatedListDTO<T>` — all paginated use case returns
- `AbstractEloquentRepository` — all repository implementations

### Namespace Updates (Phases 1 & 2) — Dedicated Commit

**This MUST be a separate commit before the feature work**, to keep diffs reviewable.

Moving Category/Brand VOs to subdirectories will update **29 occurrences across 25 files**:
- Sync use cases (`SyncCategoryUseCase`, `SyncBrandUseCase`, `SyncCategoriesUseCase`, `SyncBrandsUseCase`)
- Delete use cases (`DeleteCategoryUseCase`, `DeleteBrandUseCase`)
- Webhook controllers (`ShopwiredWebhookCategoryController`, `ShopwiredWebhookBrandController`)
- Sync jobs (`SyncShopwiredCategoryJob`, `SyncShopwiredBrandJob`)
- Repository interfaces and implementations
- Eloquent models (`CategoryModel`, `BrandModel`)
- Tests
- ShopWired API response DTOs (Infrastructure)
- Any file importing `Category`, `Brand`, `CategoryImage`, `BrandImage`

Use `git mv` for renames to preserve history. Run `make fix` + `make lint` after moves.

**After the move, `app/Domain/Catalog/ValueObjects/` will be empty** (all 4 files moved out). Remove the empty directory.

**Verified:** Deptrac and PHPArkitect configs do NOT reference the old namespace — no config changes needed.

### Default Sort Orders

| Entity | Default Sort | Direction |
|--------|-------------|-----------|
| Categories | `sort_order` | ASC |
| Brands | `sort_order` | ASC |
| Filter Groups | `sort_order` | ASC |
| Orders | `order_placed_at` | DESC |
| Customers | `created_at` | DESC |

---

## Implementation Order

Phases 1-2 (Categories & Brands) can be done together in one PR — they follow identical patterns and share no dependencies.

Phase 3 (Filter Groups) is independent and trivially small — can join the same PR or be its own.

Phase 4 (Orders) is the most complex and should be its own PR due to nested sub-resources.

Phase 5 (Customers) depends on no other phase and can be its own PR.

**Suggested PR grouping:**
1. **PR 1:** Categories + Brands + Filter Groups (catalog data)
2. **PR 2:** Orders (complex, standalone)
3. **PR 3:** Customers (standalone)

---

## Testing Strategy

Per `tests/TestingStrategy.md`, follow test pyramid by layer:

| Layer | Test Type | What to Test |
|-------|-----------|-------------|
| Domain VOs (CategoryView, BrandView, OrderView, CustomerView) | Unit | Constructor logic, any computed fields |
| Use Cases | Unit (mocked repo) | Delegation, logging, result wrapping |
| Repositories (paginate, findForApi) | Integration | Query correctness, include loading, 404 on missing |
| Controllers + Routes | Feature | Full HTTP roundtrip, status codes, JSON structure, auth gate |
| Request DTOs | Unit | Validation rules, include allowlists |

## Verification

For each phase:
1. `make lint` — all linters pass (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
2. `make test` — all existing tests pass + new tests per strategy above
3. Manual API testing via local bypass header:
   - `GET /api/categories` — returns paginated list sorted by sort_order
   - `GET /api/categories/{id}?include=description,parent_ids` — returns detail with embeds
   - `GET /api/orders?date_from=2026-01-01&date_to=2026-03-01` — returns filtered, paginated orders
   - Same for brands, filter-groups, customers
4. Verify 404 for non-existent IDs
5. Verify 422 for invalid includes
