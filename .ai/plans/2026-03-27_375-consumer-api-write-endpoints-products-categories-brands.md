# Plan: Consumer API Write Endpoints for Products, Categories, and Brands (#372)

## Context

The consumer API has read endpoints for categories and brands (PR #373) and write endpoints for products (custom fields only). The frontend needs:
1. `PUT /api/{entity}/{id}` — scalar field updates for **all three entity types** (products, categories, brands)
2. Custom field read/write endpoints for categories and brands (products already have these)

The infrastructure is partially in place:
- **Products**: Enum, VO, and `ProductFieldUpdateClient` already support 6 fields (Title, Description, MetaTitle, MetaDescription, Categories, SortOrder). Just needs a consumer API endpoint.
- **Categories/Brands**: Enum, VO, and `FieldUpdateClient` exist but only support `Title`. Need expansion to 4 fields.
- `CustomFieldValueFactory` (strict write-path validator) hardcodes `CustomFieldItemType::Product` — needs parameterising.

---

## Phase 1: Domain — Expand Enums and VOs

**Goal**: Enable Description, MetaTitle, MetaDescription as updatable fields.

### 1.1 Expand `CategoryUpdatableField` enum
- **File**: `app/Domain/Catalog/Category/Enums/CategoryUpdatableField.php`
- Add cases: `Description`, `MetaTitle`, `MetaDescription`

### 1.2 Expand `BrandUpdatableField` enum
- **File**: `app/Domain/Catalog/Brand/Enums/BrandUpdatableField.php`
- Add cases: `Description`, `MetaTitle`, `MetaDescription`

### 1.3 Expand `CategoryFieldUpdate` VO
- **File**: `app/Domain/Catalog/Category/ValueObjects/CategoryFieldUpdate.php`
- Add static factories: `description()`, `metaTitle()`, `metaDescription()`

### 1.4 Expand `BrandFieldUpdate` VO
- **File**: `app/Domain/Catalog/Brand/ValueObjects/BrandFieldUpdate.php`
- Add static factories: `description()`, `metaTitle()`, `metaDescription()`

---

## Phase 2: Infrastructure — Parameterise Factory + New Update Clients

### 2.1 Parameterise `CustomFieldValueFactory`
- **File**: `app/Infrastructure/Shopwired/Factories/CustomFieldValueFactory.php`
- Add `CustomFieldItemType $itemType` constructor parameter
- Use `$this->itemType` at line 64 (exception) and line 234 (registry loading) instead of hardcoded `Product`

### 2.2 Expand `CategoryFieldUpdateClient` (scalar fields only)
- **File**: `app/Infrastructure/Shopwired/Clients/CategoryFieldUpdateClient.php`
- Expand `mapField()` with: `Description => 'description'`, `MetaTitle => 'metaTitle'`, `MetaDescription => 'metaDescription'`
- **No other changes** — this client stays focused on simple PUT for scalar fields

### 2.3 Expand `BrandFieldUpdateClient` (scalar fields only)
- **File**: `app/Infrastructure/Shopwired/Clients/BrandFieldUpdateClient.php`
- Same `mapField()` expansion as 2.2

### 2.4 New `CategoryUpdateClientInterface`
- **New file**: `app/Application/Contracts/Shopwired/CategoryUpdateClientInterface.php`
- Mirrors `ProductUpdateClientInterface` — fetch-merge-PUT for embedded collections
- Method: `updateCustomFields(int $categoryId, array $customFields): void`
- Declare `@throws` for API exceptions (same as `ProductUpdateClientInterface`)

### 2.5 New `BrandUpdateClientInterface`
- **New file**: `app/Application/Contracts/Shopwired/BrandUpdateClientInterface.php`
- Same pattern as 2.4

### 2.6 New `CategoryUpdateClient`
- **New file**: `app/Infrastructure/Shopwired/Clients/CategoryUpdateClient.php`
- Mirrors `ProductUpdateClient` — constructor takes `ShopwiredTransportInterface` + `CategoryClientInterface`
- `updateCustomFields()` using fetch-merge-PUT:
  - Fetch: `$this->categoryClient->getCategoryById($categoryId)` → `->customFields` (raw `array<string, mixed>`)
  - Merge: combine existing + new fields (null → empty string to clear)
  - PUT: `$this->transport->put('categories/' . $categoryId, ['customFields' => $merged])`

### 2.7 New `BrandUpdateClient`
- **New file**: `app/Infrastructure/Shopwired/Clients/BrandUpdateClient.php`
- Same pattern as 2.6 with `BrandClientInterface`

### 2.8 Update `ShopwiredServiceProvider` bindings
- **File**: `app/Providers/ShopwiredServiceProvider.php`
- **Remove** global scoped binding: `$this->app->scoped(CustomFieldValueFactoryInterface::class, CustomFieldValueFactory::class)`
- **Add** contextual bindings for `CustomFieldValueFactory` per use case:
  ```php
  $this->app->when(UpdateProductCustomFieldsUseCase::class)
      ->needs(CustomFieldValueFactoryInterface::class)
      ->give(fn($app) => new CustomFieldValueFactory(
          $app->make(CustomFieldRepositoryInterface::class),
          CustomFieldItemType::Product,
      ));
  // + Category, Brand equivalents
  ```
- **Add** new update client bindings:
  ```php
  $this->app->singleton(CategoryUpdateClientInterface::class,
      fn($app) => new CategoryUpdateClient(
          ShopwiredClientFactory::getTransport(),
          $app->make(CategoryClientInterface::class),
      ));
  // + Brand equivalent
  ```
- **Keep** existing `CategoryFieldUpdateClientInterface` / `BrandFieldUpdateClientInterface` singletons unchanged (they stay simple PUT, no new deps)

---

## Phase 3: Application — Use Cases + Shared Helper

### 3.0 Extract `CustomFieldMerger` (shared helper)
- **New file**: `app/Application/Catalog/CustomFieldMerger.php`
- Extract `mergeWithDefinitions()` from `GetProductCustomFieldsUseCase` as a `public static` method
- Signature: `static mergeWithDefinitions(list<AbstractCustomFieldValue> $populatedFields, list<CustomFieldDefinition> $definitions): list<AbstractCustomFieldValue>`
- Contains: index by name → merge with NullCustomFieldValue → append orphans → sort by sortOrder
- Refactor `GetProductCustomFieldsUseCase` to call `CustomFieldMerger::mergeWithDefinitions()`

### 3.1 `UpdateProductFieldsUseCase`
- **New file**: `app/Application/Catalog/UseCases/UpdateProductFieldsUseCase.php`
- Constructor: `ProductFieldUpdateClientInterface`, `LoggerInterface`
- `execute(IntId $productId, array $fields): void`
- Maps validated field names → `ProductFieldUpdate` VOs via match expression → calls `client->update()`
- Handles mixed types: `title`/`description`/`meta_title`/`meta_description` are strings, `sort_order` is int, `categories` is `list<int>`

### 3.2 `UpdateCategoryFieldsUseCase`
- **New file**: `app/Application/Catalog/UseCases/UpdateCategoryFieldsUseCase.php`
- Constructor: `CategoryFieldUpdateClientInterface`, `LoggerInterface`
- `execute(IntId $categoryId, array $fields): void`
- Maps validated field names → `CategoryFieldUpdate` VOs via match expression → calls `client->update()`

### 3.3 `UpdateBrandFieldsUseCase`
- **New file**: `app/Application/Catalog/UseCases/UpdateBrandFieldsUseCase.php`
- Same pattern with Brand types

### 3.4 `UpdateCategoryCustomFieldsUseCase`
- **New file**: `app/Application/Catalog/UseCases/UpdateCategoryCustomFieldsUseCase.php`
- Mirrors `UpdateProductCustomFieldsUseCase`:
  - Validate via `CustomFieldSubmissionValidator` → `orFail()`
  - Call `CategoryUpdateClientInterface::updateCustomFields()` (new interface, not FieldUpdateClient)

### 3.5 `UpdateBrandCustomFieldsUseCase`
- **New file**: `app/Application/Catalog/UseCases/UpdateBrandCustomFieldsUseCase.php`
- Same pattern with `BrandUpdateClientInterface`

### 3.6 `GetCategoryCustomFieldsUseCase`
- **New file**: `app/Application/Catalog/UseCases/GetCategoryCustomFieldsUseCase.php`
- Fetch category via `CategoryRepositoryInterface::findCategoryForApi()` with `['custom_fields']`
- Get definitions via `CustomFieldRepositoryInterface::findByItemType(Category)`
- Defensive: `$category->customFields ?? []` (CategoryView has nullable custom fields)
- Call `CustomFieldMerger::mergeWithDefinitions()` + optional field name filter

### 3.7 `GetBrandCustomFieldsUseCase`
- **New file**: `app/Application/Catalog/UseCases/GetBrandCustomFieldsUseCase.php`
- Same pattern with `BrandRepositoryInterface` + `$brand->customFields ?? []`

---

## Phase 4: Presentation — Controllers, DTOs, Routes

### 4.1 Request DTOs (new files in `app/Presentation/Http/Api/DTOs/`)

| DTO | Validates | Pattern |
|-----|-----------|---------|
| `UpdateProductFieldsRequestDTO` | `fields` required array, min:1, keys in `[title, description, meta_title, meta_description, categories, sort_order]`. Text fields: string. `categories`: `list<int>`. `sort_order`: int. | Wrapped key-value map with mixed types |
| `UpdateCategoryFieldsRequestDTO` | `fields` required array, min:1, keys in `[title, description, meta_title, meta_description]`, string values | Wrapped key-value map (string-only) |
| `UpdateBrandFieldsRequestDTO` | Same allowed keys as category | Same |
| `UpdateCategoryCustomFieldsRequestDTO` | `custom_fields` required array, min:1 | Mirrors `UpdateProductCustomFieldsRequestDTO` |
| `UpdateBrandCustomFieldsRequestDTO` | Same | Same |
| `GetCategoryCustomFieldsRequestDTO` | Optional `fields` array filter | Mirrors `GetProductCustomFieldsRequestDTO` |
| `GetBrandCustomFieldsRequestDTO` | Same | Same |

**Product fields DTO — validation detail:**
```php
'fields' => ['required', 'array', 'min:1'],
'fields.title' => ['string', 'max:255'],
'fields.description' => ['string'],
'fields.meta_title' => ['string', 'max:255'],
'fields.meta_description' => ['string', 'max:500'],
'fields.categories' => ['array'],
'fields.categories.*' => ['integer', 'min:1'],
'fields.sort_order' => ['integer', 'min:0'],
```
Plus a custom rule rejecting unknown keys.

### 4.2 Controllers

**Refactored `ProductUpdateController`** — move from `Presentation/Http/Controllers/Shopwired/` to `app/Presentation/Http/Api/Controllers/ProductUpdateController.php`:
- Move all 3 existing actions: `updateFreeDelivery()`, `updatePrices()`, `updateCustomFields()`
- Add new: `updateFields(int $productId, UpdateProductFieldsRequestDTO $data): JsonResponse` → 204
- Delete old `Presentation/Http/Controllers/Shopwired/ProductUpdateController.php`
- The frontend shouldn't know about "ShopWired" as an implementation detail — all product write endpoints live under the consumer API

**New `CategoryUpdateController`** — `app/Presentation/Http/Api/Controllers/CategoryUpdateController.php`:
- `updateFields(int $categoryId, UpdateCategoryFieldsRequestDTO $data): JsonResponse` → 204
- `updateCustomFields(int $categoryId, UpdateCategoryCustomFieldsRequestDTO $data): JsonResponse` → 204

**New `BrandUpdateController`** — `app/Presentation/Http/Api/Controllers/BrandUpdateController.php`:
- `updateFields(int $brandId, UpdateBrandFieldsRequestDTO $data): JsonResponse` → 204
- `updateCustomFields(int $brandId, UpdateBrandCustomFieldsRequestDTO $data): JsonResponse` → 204

**Extend existing `CategoryController`** — add custom field read:
- `customFields(int $categoryId, GetCategoryCustomFieldsRequestDTO $data): JsonResponse`

**Extend existing `BrandController`** — add custom field read:
- `customFields(int $brandId, GetBrandCustomFieldsRequestDTO $data): JsonResponse`

### 4.3 Routes
- **File**: `routes/api.php`
- Add to consumer API group (inside the existing middleware block):

```php
// === Consumer API group (JWT + approval gate) ===

// Product write endpoints (unified — moved from /api/shopwired/ prefix)
Route::put('products/{productId}', [ProductUpdateController::class, 'updateFields'])
    ->whereNumber('productId');
Route::post('products/{productId}/custom-fields', [ProductUpdateController::class, 'updateCustomFields'])
    ->whereNumber('productId');
Route::post('products/{productId}/prices', [ProductUpdateController::class, 'updatePrices'])
    ->whereNumber('productId');
Route::post('products/free-delivery', [ProductUpdateController::class, 'updateFreeDelivery']);

// Category endpoints
Route::put('categories/{categoryId}', [CategoryUpdateController::class, 'updateFields'])
    ->whereNumber('categoryId');
Route::get('categories/{categoryId}/custom-fields', [CategoryController::class, 'customFields'])
    ->whereNumber('categoryId');
Route::post('categories/{categoryId}/custom-fields', [CategoryUpdateController::class, 'updateCustomFields'])
    ->whereNumber('categoryId');

// Brand endpoints
Route::put('brands/{brandId}', [BrandUpdateController::class, 'updateFields'])
    ->whereNumber('brandId');
Route::get('brands/{brandId}/custom-fields', [BrandController::class, 'customFields'])
    ->whereNumber('brandId');
Route::post('brands/{brandId}/custom-fields', [BrandUpdateController::class, 'updateCustomFields'])
    ->whereNumber('brandId');
```

**Route changes for existing product endpoints:**
- `POST /api/shopwired/products/free-delivery` → `POST /api/products/free-delivery` (URL change + adds approval gate)
- `POST /api/shopwired/products/{id}/prices` → `POST /api/products/{id}/prices` (URL change + adds approval gate)
- `POST /api/products/{id}/custom-fields` — no change (already in consumer API group)
- Remove the entire `Route::prefix('shopwired')` block from the "Authenticated API" group (lines 128-134)

**Frontend impact**: The frontend needs to update 2 API endpoint URLs (remove `/shopwired/` prefix from prices and free-delivery calls). All product write endpoints now require the approval gate.

---

## Phase 5: Tests

### Unit tests (new files in `tests/Unit/Application/Catalog/UseCases/`)
- `UpdateProductFieldsUseCaseTest` — validates field mapping (mixed types) and client call
- `UpdateCategoryFieldsUseCaseTest` — validates field mapping and client call
- `UpdateBrandFieldsUseCaseTest`
- `UpdateCategoryCustomFieldsUseCaseTest` — validates validation + client call (mirrors product test)
- `UpdateBrandCustomFieldsUseCaseTest`
- `GetCategoryCustomFieldsUseCaseTest` — validates merge, filter, sort logic
- `GetBrandCustomFieldsUseCaseTest`

### Shared helper test
- `tests/Unit/Application/Catalog/CustomFieldMergerTest.php` — merge, sort, orphan handling

### Unit tests for infrastructure
- `tests/Unit/Infrastructure/Shopwired/Clients/CategoryUpdateClientTest.php` — fetch-merge-PUT
- `tests/Unit/Infrastructure/Shopwired/Clients/BrandUpdateClientTest.php`
- Update existing `CategoryFieldUpdateClientTest` / `BrandFieldUpdateClientTest` (if they exist) for new mapField cases

---

## Key Files Modified (existing)

| File | Change |
|------|--------|
| `app/Domain/Catalog/Category/Enums/CategoryUpdatableField.php` | +3 cases |
| `app/Domain/Catalog/Brand/Enums/BrandUpdatableField.php` | +3 cases |
| `app/Domain/Catalog/Category/ValueObjects/CategoryFieldUpdate.php` | +3 factory methods |
| `app/Domain/Catalog/Brand/ValueObjects/BrandFieldUpdate.php` | +3 factory methods |
| `app/Infrastructure/Shopwired/Factories/CustomFieldValueFactory.php` | Parameterise with `CustomFieldItemType` |
| `app/Infrastructure/Shopwired/Clients/CategoryFieldUpdateClient.php` | Expand `mapField()` only |
| `app/Infrastructure/Shopwired/Clients/BrandFieldUpdateClient.php` | Expand `mapField()` only |
| `app/Providers/ShopwiredServiceProvider.php` | Contextual bindings for factory + new update client bindings |
| `app/Presentation/Http/Api/Controllers/CategoryController.php` | +`customFields()` method + use case injection |
| `app/Presentation/Http/Api/Controllers/BrandController.php` | +`customFields()` method + use case injection |
| `app/Application/Catalog/UseCases/GetProductCustomFieldsUseCase.php` | Refactor to use `CustomFieldMerger` |
| `app/Presentation/Http/Controllers/Shopwired/ProductUpdateController.php` | **DELETE** — moved to `Api/Controllers/` |
| `routes/api.php` | +7 new routes, remove `/api/shopwired/` block, move 2 product routes to consumer API group |

## Key Files Created (new)

| File | Purpose |
|------|---------|
| `app/Application/Contracts/Shopwired/CategoryUpdateClientInterface.php` | Fetch-merge-PUT contract (custom fields) |
| `app/Application/Contracts/Shopwired/BrandUpdateClientInterface.php` | Same |
| `app/Infrastructure/Shopwired/Clients/CategoryUpdateClient.php` | Fetch-merge-PUT implementation |
| `app/Infrastructure/Shopwired/Clients/BrandUpdateClient.php` | Same |
| `app/Application/Catalog/CustomFieldMerger.php` | Shared merge-with-definitions logic |
| `app/Application/Catalog/UseCases/UpdateProductFieldsUseCase.php` | Product scalar field update orchestration |
| `app/Application/Catalog/UseCases/UpdateCategoryFieldsUseCase.php` | Category scalar field update orchestration |
| `app/Application/Catalog/UseCases/UpdateBrandFieldsUseCase.php` | Same |
| `app/Application/Catalog/UseCases/UpdateCategoryCustomFieldsUseCase.php` | Custom field write orchestration |
| `app/Application/Catalog/UseCases/UpdateBrandCustomFieldsUseCase.php` | Same |
| `app/Application/Catalog/UseCases/GetCategoryCustomFieldsUseCase.php` | Custom field read orchestration |
| `app/Application/Catalog/UseCases/GetBrandCustomFieldsUseCase.php` | Same |
| `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` | Moved from Shopwired namespace + new updateFields() |
| `app/Presentation/Http/Api/Controllers/CategoryUpdateController.php` | Category write endpoints |
| `app/Presentation/Http/Api/Controllers/BrandUpdateController.php` | Brand write endpoints |
| `app/Presentation/Http/Api/DTOs/UpdateProductFieldsRequestDTO.php` | Product field validation (mixed types) |
| `app/Presentation/Http/Api/DTOs/UpdateCategoryFieldsRequestDTO.php` | Validation |
| `app/Presentation/Http/Api/DTOs/UpdateBrandFieldsRequestDTO.php` | Same |
| `app/Presentation/Http/Api/DTOs/UpdateCategoryCustomFieldsRequestDTO.php` | Same |
| `app/Presentation/Http/Api/DTOs/UpdateBrandCustomFieldsRequestDTO.php` | Same |
| `app/Presentation/Http/Api/DTOs/GetCategoryCustomFieldsRequestDTO.php` | Same |
| `app/Presentation/Http/Api/DTOs/GetBrandCustomFieldsRequestDTO.php` | Same |

---

## Verification

1. `make lint` — all 5 linters pass (Pint, PHPStan, PHPArkitect, Deptrac, TLint)
2. `make test` — all existing + new tests pass
3. Manual API testing via local bypass header:
   - `PUT /api/products/{id}` with `{"fields": {"title": "Test", "categories": [1, 2], "sort_order": 5}}` → 204
   - `PUT /api/categories/{id}` with `{"fields": {"title": "Test", "description": "Desc"}}` → 204
   - `PUT /api/brands/{id}` with `{"fields": {"title": "Test", "meta_title": "SEO"}}` → 204
   - `POST /api/categories/{id}/custom-fields` with `{"custom_fields": {"field_name": "value"}}` → 204
   - `GET /api/categories/{id}/custom-fields` → returns enriched field list with definition metadata
   - Same custom-fields endpoints for brands
   - Verify validation errors (unknown fields, type mismatches, wrong types) return 422
   - Verify unknown keys in `fields` are rejected
