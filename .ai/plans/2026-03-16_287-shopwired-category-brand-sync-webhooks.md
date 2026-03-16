# Plan: ShopWired Category & Brand Sync + Webhooks

## Context

Categories and Brands are two remaining ShopWired entities that need full sync + webhook support. Categories are ~50% built (domain VO, API client, response DTO exist). Brands have nothing except webhook topic enum cases. Both are small, stable datasets similar to FilterGroups — simple fetch-all-and-save pattern.

**Key early risk**: Brand custom fields may not be supported by the API (not in spec). We'll build the BrandClient with `custom_fields` embed and test against the live API early. If unsupported, we remove the embed — minimal rework.

**Delivery**: Single branch/PR for both entities.

---

## Phase 0: Early API Validation (Brand Custom Fields)

**Goal**: Confirm whether `/brands?embed=custom_fields` works before building the full stack.

**Note**: Phase 0 requires building the Brand domain VO, BrandResponse DTO, and BrandClientInterface first (they're dependencies of BrandClient). In practice, execute Phases 1c + 2a + 2b + 2c, then test.

1. Build Brand domain VO, response DTOs, client interface, and client with `custom_fields` in embeds
2. Wire in service provider and test via `php artisan tinker` against live API
3. If unsupported: remove `custom_fields` from BrandClient embeds/fields and BrandResponse
4. If supported: proceed as planned

---

## Phase 1: Domain Layer

### 1a. Update Category Value Object
**File**: `app/Domain/Catalog/ValueObjects/Category.php`
- Add `id: int` and `createdAt: DateTimeImmutable` properties (first two constructor params)
- Add assertions: `Assert::greaterThan($id, 0)`
- **Replace `parents: list<Category>` with `parentIds: list<int>`** — store only parent external IDs, not full Category objects. The API's parent embed data is incomplete/mismatched anyway. Future DB-based parent resolution can be built on top of this.
- Update all existing usages (CategoryTest.php has 7+ constructions — all need `id`, `createdAt`, and `parentIds` changes)

### 1b. Update CategoryResponse DTO
**File**: `app/Infrastructure/Shopwired/Responses/CategoryResponse.php`
- Update `toDomain()` to pass `id` and parsed `createdAt` (with DateTimeImmutable parsing + error handling)
- **Transform `parents: list<CategoryResponse>` → `parentIds: list<int>`** by extracting IDs: `array_map(fn($p) => $p->id, $this->parents)` — no more recursive `toDomain()` on parents

### 1c. Create Brand Domain Objects
**New file**: `app/Domain/Catalog/ValueObjects/Brand.php`
```
final readonly class Brand {
    id: int, createdAt: DateTimeImmutable, title: string, description: ?string,
    slug: string, url: string, active: bool, featured: bool, sortOrder: int,
    metaTitle: ?string, metaKeywords: ?string, metaDescription: ?string,
    image: ?BrandImage, customFields: array
}
```

**New file**: `app/Domain/Catalog/ValueObjects/BrandImage.php`
```
final readonly class BrandImage { url: string }
```

### 1d. Create Webhook Intent Enums
**New file**: `app/Domain/Catalog/Category/Enums/CategoryWebhookIntent.php`
```php
enum CategoryWebhookIntent { case Sync; case Deleted; }
```

**New file**: `app/Domain/Catalog/Brand/Enums/BrandWebhookIntent.php`
```php
enum BrandWebhookIntent { case Sync; case Deleted; }
```

---

## Phase 2: Infrastructure Layer — API & DTOs

### 2a. Brand API Client
**New file**: `app/Infrastructure/Shopwired/Clients/BrandClient.php`
- Pattern: Follow `CategoryClient` exactly
- Endpoint: `'brands'`
- Methods: `listAllBrands()`, `listBrands()`, `getBrandById(int)`, `getBrandCount()`
- Default embeds: `['custom_fields']` (pending Phase 0 validation)
- Default fields: `['id', 'createdAt', 'title', 'description', 'slug', 'url', 'active', 'featured', 'sortOrder', 'metaTitle', 'metaKeywords', 'metaDescription', 'image', 'customFields']`

**New file**: `app/Application/Contracts/Shopwired/BrandClientInterface.php`
- Pattern: Follow `CategoryClientInterface`

### 2b. Brand Response DTOs
**New file**: `app/Infrastructure/Shopwired/Responses/BrandResponse.php`
- Pattern: Follow `CategoryResponse`
- Implements `DomainConvertibleInterface`
- Properties matching Brand sample data (skip `contactInformation`)
- `toDomain()` → `Brand`

**New file**: `app/Infrastructure/Shopwired/Responses/BrandImageResponse.php`
- Pattern: Follow `CategoryImageResponse`

### 2c. Factory Registration
**File**: `app/Infrastructure/Shopwired/ShopwiredClientFactory.php`
- Add `createBrandClient(): BrandClientInterface`

---

## Phase 3: Database Layer

### 3a. Categories Migration
**New file**: `database/migrations/YYYY_MM_DD_HHMMSS_create_shopwired_categories_table.php`
```
shopwired.categories:
  id: uuid PK (gen_random_uuid())
  external_id: integer UNIQUE
  shopwired_created_at: timestampTz
  title: string(255)
  description: text nullable
  description2: text nullable
  slug: string(255)
  url: string(500)
  active: boolean
  featured: boolean
  trade_only: boolean
  sort_order: smallInteger
  meta_title: string(255) nullable
  meta_description: text nullable
  meta_keywords: string(500) nullable
  meta_no_index: boolean
  image_url: string(500) nullable
  parent_ids: jsonb default '[]'  // Array of parent category external_ids (extracted from API embed)
  custom_fields: jsonb default '{}'
  created_at, updated_at: timestampTz
```

### 3b. Brands Migration
**New file**: `database/migrations/YYYY_MM_DD_HHMMSS_create_shopwired_brands_table.php`
```
shopwired.brands:
  id: uuid PK (gen_random_uuid())
  external_id: integer UNIQUE
  shopwired_created_at: timestampTz
  title: string(255)
  description: text nullable
  slug: string(255)
  url: string(500)
  active: boolean
  featured: boolean
  sort_order: smallInteger
  meta_title: string(255) nullable
  meta_description: text nullable
  meta_keywords: string(500) nullable
  image_url: string(500) nullable
  custom_fields: jsonb default '{}'
  created_at, updated_at: timestampTz
```

### 3c. Eloquent Models
**New file**: `app/Infrastructure/Shopwired/Models/CategoryModel.php`
- Implements `EloquentDomainMappableInterface<Category>`
- Table: `shopwired.categories`
- `toDomain()` and `fromDomainAttributes()` methods
- Casts: external_id (int), active/featured/trade_only/meta_no_index (bool), parent_ids (array), custom_fields (array)

**New file**: `app/Infrastructure/Shopwired/Models/BrandModel.php`
- Same pattern, table: `shopwired.brands`
- No trade_only, no description2, no meta_no_index, no parent_ids

---

## Phase 4: Repository Layer

### 4a. Category Repository
**New file**: `app/Application/Contracts/Shopwired/CategoryRepositoryInterface.php`
- Extends `RepositoryWriteInterface<Category>`
- Methods: `findAll(): array`, `findByExternalId(int): ?Category`, `saveFromWebhook(Category, array): void`, `deleteByExternalId(IntId): void`

**New file**: `app/Infrastructure/Shopwired/Repositories/EloquentCategoryRepository.php`
- Extends `AbstractEloquentRepository`
- Pattern: Follow `EloquentFilterGroupRepository` for basic methods
- Add `saveFromWebhook()` and `deleteByExternalId()` for webhook support (follow `EloquentCustomerRepository` pattern)

### 4b. Brand Repository
**New file**: `app/Application/Contracts/Shopwired/BrandRepositoryInterface.php`
- Same pattern as CategoryRepositoryInterface

**New file**: `app/Infrastructure/Shopwired/Repositories/EloquentBrandRepository.php`
- Same pattern as EloquentCategoryRepository

---

## Phase 5: Application Layer — Sync

### 5a. Sync Use Cases
**New file**: `app/Application/Shopwired/UseCases/SyncCategoriesUseCase.php`
- Pattern: Follow `SyncFilterGroupsUseCase` exactly
- Constructor: `CategoryClientInterface`, `CategoryRepositoryInterface`, `LoggerInterface`
- Flow: `listAllCategories() → saveMany() → SyncResult`

**New file**: `app/Application/Shopwired/UseCases/SyncBrandsUseCase.php`
- Same pattern with Brand equivalents

### 5b. Bulk Sync Jobs
**New file**: `app/Application/Jobs/Shopwired/SyncShopwiredCategoriesJob.php`
- Pattern: Follow `SyncShopwiredFilterGroupsJob`
- Queue: `QueueName::Low`, tries: 3, timeout: 60s, uniqueFor: 120s

**New file**: `app/Application/Jobs/Shopwired/SyncShopwiredBrandsJob.php`
- Same pattern

### 5c. Single-Entity Sync Jobs (for webhooks)
**New file**: `app/Application/Jobs/Shopwired/SyncShopwiredCategoryJob.php`
- Extends `AbstractSyncShopwiredEntityJob`
- Pattern: Follow `SyncShopwiredCustomerJob`
- `handle()`: `$client->getCategoryById($this->entityId->value)` → `$this->executeSync()`

**New file**: `app/Application/Jobs/Shopwired/SyncShopwiredBrandJob.php`
- Same pattern with Brand equivalents

### 5d. Schedule Registration
**File**: `app/Providers/Schedule/ShopwiredScheduleServiceProvider.php`
- Add `registerCategorySchedules()` and `registerBrandSchedules()`
- Daily at a suitable UK time (e.g., 08:00 for categories, 08:05 for brands — after existing product sync window)
- Small datasets, quick sync (~5s each)

---

## Phase 6: Webhook System

### 6a. Update WebhookTopic Enums
**File**: `app/Application/Shopwired/Enums/WebhookTopic.php`
- Add: `CategoryCreated`, `CategoryUpdated`, `CategoryDeleted`, `BrandCreated`, `BrandUpdated`, `BrandDeleted`

**File**: `app/Infrastructure/Shopwired/Enums/WebhookTopic.php`
- Already has all 6 cases — no changes needed

### 6b. Webhook Resolvers (Infrastructure)
**New file**: `app/Infrastructure/Shopwired/Resolvers/ShopwiredCategoryWebhookEventResolver.php`
- Pattern: Follow `ShopwiredCustomerWebhookEventResolver`
- Maps `category.created`, `category.updated` → `CategoryWebhookIntent::Sync`
- Maps `category.deleted` → `CategoryWebhookIntent::Deleted`

**New file**: `app/Infrastructure/Shopwired/Resolvers/ShopwiredBrandWebhookEventResolver.php`
- Same pattern for brands

### 6c. Webhook Response DTOs
**New file**: `app/Infrastructure/Shopwired/Responses/CategoryWebhookResponse.php`
- Pattern: Follow `CustomerWebhookResponse`
- Uses `Spatie\LaravelData\Optional` for embed fields (`parents`, `customFields`)
- Has `presentEmbeds()` method
- `toDomain()` coalesces Optional to empty arrays

**New file**: `app/Infrastructure/Shopwired/Responses/BrandWebhookResponse.php`
- Same pattern, with `customFields` as the only embed field

### 6d. Webhook Parsers (Infrastructure)
**New file**: `app/Infrastructure/Shopwired/Parsers/ShopwiredCategoryWebhookParser.php`
- Pattern: Follow `ShopwiredCustomerWebhookParser`
- Parses `$data['object']` via `CategoryWebhookResponse`
- Returns `WebhookCategoryResultDTO`

**New file**: `app/Infrastructure/Shopwired/Parsers/ShopwiredBrandWebhookParser.php`
- Same pattern

### 6e. Webhook Result DTOs (Application)
**New file**: `app/Application/Shopwired/DTOs/WebhookCategoryResultDTO.php`
- Pattern: Follow `WebhookCustomerResultDTO`
- `category: Category`, `presentEmbeds: list<string>`

**New file**: `app/Application/Shopwired/DTOs/WebhookBrandResultDTO.php`
- Same pattern

### 6f. Webhook Parser/Resolver Interfaces (Application Contracts)
**New file**: `app/Application/Contracts/Shopwired/CategoryWebhookParserInterface.php`
**New file**: `app/Application/Contracts/Shopwired/CategoryWebhookEventResolverInterface.php`
**New file**: `app/Application/Contracts/Shopwired/BrandWebhookParserInterface.php`
**New file**: `app/Application/Contracts/Shopwired/BrandWebhookEventResolverInterface.php`
- Pattern: Follow Customer equivalents

### 6g. Webhook Use Cases (Application)
**New file**: `app/Application/Shopwired/UseCases/Webhooks/SyncCategoryUseCase.php`
- Pattern: Follow `SyncCustomerUseCase`
- Staleness check → idempotency check → `saveFromWebhook()` → record event → dispatch `SyncShopwiredCategoryJob`

**New file**: `app/Application/Shopwired/UseCases/Webhooks/DeleteCategoryUseCase.php`
- Pattern: Follow `DeleteCustomerUseCase`
- `deleteByExternalId()` with idempotent `ResourceNotFoundException` catch

**New files**: Same pair for Brand (`SyncBrandUseCase`, `DeleteBrandUseCase`)

### 6h. Webhook Handler Services (Application)
**New file**: `app/Application/Shopwired/Services/HandleCategoryWebhookService.php`
- Pattern: Follow `HandleCustomerWebhookService`
- Routes `CategoryWebhookIntent::Sync` → `SyncCategoryUseCase`
- Routes `CategoryWebhookIntent::Deleted` → `DeleteCategoryUseCase`

**New file**: `app/Application/Shopwired/Services/HandleBrandWebhookService.php`
- Same pattern

### 6i. Webhook Controllers (Presentation)
**New file**: `app/Presentation/Http/Controllers/Shopwired/Webhooks/ShopwiredWebhookCategoryController.php`
- Pattern: Follow `ShopwiredWebhookCustomerController`

**New file**: `app/Presentation/Http/Controllers/Shopwired/Webhooks/ShopwiredWebhookBrandController.php`
- Same pattern

### 6j. Routes
**File**: `routes/api.php`
- Add within the webhook group:
```php
Route::post('categories', ShopwiredWebhookCategoryController::class);
Route::post('brands', ShopwiredWebhookBrandController::class);
```

---

## Phase 7: Service Provider Wiring

**File**: `app/Providers/ShopwiredServiceProvider.php`

Add registrations for:
- `BrandClientInterface` → singleton via factory
- `CategoryRepositoryInterface` → singleton `EloquentCategoryRepository`
- `BrandRepositoryInterface` → singleton `EloquentBrandRepository`
- `CategoryWebhookEventResolverInterface` → singleton
- `BrandWebhookEventResolverInterface` → singleton
- `CategoryWebhookParserInterface` → singleton
- `BrandWebhookParserInterface` → singleton
- Add `SyncCategoryUseCase` and `SyncBrandUseCase` to `$webhookStalenessHours` `when()` binding
- Update `provides()` array with all new interfaces

---

## Phase 8: Tests

Follows `tests/TestingStrategy.md` — test what matters, not what the type system guarantees.

### Domain Layer (Unit, 90%+ coverage, MSI 85%+)
- `tests/Unit/Domain/Catalog/ValueObjects/CategoryTest.php` — **update** for `id`, `createdAt`, `parentIds` changes (breaking constructor change)
- `tests/Unit/Domain/Catalog/ValueObjects/BrandTest.php` — **new**: construction, assertions, edge cases
- ~~`BrandImageTest.php`~~ — **SKIP**: Simple DTO with no logic (`{ url: string }`), type system covers it

### Application Layer (Unit, 70%+ coverage)
- `tests/Unit/Application/Shopwired/UseCases/Webhooks/SyncCategoryUseCaseTest.php` — **new**: staleness check, idempotency check, dispatch branching (real business logic)
- `tests/Unit/Application/Shopwired/UseCases/Webhooks/SyncBrandUseCaseTest.php` — **new**: same pattern
- `tests/Unit/Application/Shopwired/UseCases/Webhooks/DeleteCategoryUseCaseTest.php` — **new**: idempotent delete (ResourceNotFoundException catch)
- `tests/Unit/Application/Shopwired/UseCases/Webhooks/DeleteBrandUseCaseTest.php` — **new**: same
- ~~`SyncCategoriesUseCaseTest`~~ — **SKIP**: Pure orchestration (fetch → save → return), no branching beyond zero-check
- ~~`SyncBrandsUseCaseTest`~~ — **SKIP**: Same reasoning

### Infrastructure Layer (Unit for pure logic, no coverage target)
- `tests/Unit/Infrastructure/Shopwired/Responses/CategoryWebhookResponseTest.php` — **new**: `presentEmbeds()` detection logic (follows existing `ProductWebhookResponseTest` pattern)
- `tests/Unit/Infrastructure/Shopwired/Responses/BrandWebhookResponseTest.php` — **new**: same
- ~~`BrandResponseTest.php`~~ — **SKIP**: `toDomain()` is simple mapping, type system covers it
- ~~Resolver tests~~ — **SKIP**: Simple match statements, no real logic beyond what PHPStan catches

### Presentation Layer — **SKIP**
- Webhook controllers are pure delegation (no branching). Existing controllers have no tests.

### NOT testing (per strategy):
- Service providers, exception classes, simple DTOs, sync jobs (framework mechanics), bulk sync use cases (pure orchestration), resolvers (trivial match), API client methods (would need Http::fake integration tests — low ROI for these simple clients)

---

## Execution Order

1. **Phase 1**: Domain objects — Category VO update (add id, createdAt, replace parents→parentIds) + Brand VO/Image + Intent enums
2. **Phase 2**: Infrastructure API layer — Brand response DTOs, BrandClientInterface, BrandClient, factory method
3. **Phase 0 (API test)**: Wire BrandClient in service provider, test `custom_fields` embed via tinker against live API. Adjust if unsupported.
4. **Phase 1b**: Update CategoryResponse::toDomain() for new Category VO signature + update CategoryTest.php
5. **Phase 3**: Database (migrations, models)
6. **Phase 4**: Repositories
7. **Phase 5**: Sync use cases + jobs + scheduling
8. **Phase 6**: Webhook system (resolvers, parsers, DTOs, use cases, handlers, controllers, routes)
9. **Phase 7**: Service provider wiring (remaining bindings)
10. **Phase 8**: Tests
11. Run `make lint` + `make test` throughout

---

## Key Files to Reuse (Templates)

| Pattern | Template File |
|---------|--------------|
| Domain VO | `app/Domain/Catalog/Filters/ValueObjects/FilterGroupDefinition.php` |
| API Client | `app/Infrastructure/Shopwired/Clients/CategoryClient.php` |
| Response DTO | `app/Infrastructure/Shopwired/Responses/CategoryResponse.php` |
| Webhook Response | `app/Infrastructure/Shopwired/Responses/CustomerWebhookResponse.php` |
| Migration | `database/migrations/2026_02_05_162433_create_shopwired_filter_groups_table.php` |
| Model | `app/Infrastructure/Shopwired/Models/FilterGroupDefinitionModel.php` |
| Repository | `app/Infrastructure/Shopwired/Repositories/EloquentFilterGroupRepository.php` |
| Sync Use Case | `app/Application/Shopwired/UseCases/SyncFilterGroupsUseCase.php` |
| Bulk Sync Job | `app/Application/Jobs/Shopwired/SyncShopwiredFilterGroupsJob.php` |
| Entity Sync Job | `app/Application/Jobs/Shopwired/SyncShopwiredCustomerJob.php` |
| Webhook Controller | `app/Presentation/Http/Controllers/Shopwired/Webhooks/ShopwiredWebhookCustomerController.php` |
| Webhook Handler | `app/Application/Shopwired/Services/HandleCustomerWebhookService.php` |
| Webhook Resolver | `app/Infrastructure/Shopwired/Resolvers/ShopwiredCustomerWebhookEventResolver.php` |
| Webhook Parser | `app/Infrastructure/Shopwired/Parsers/ShopwiredCustomerWebhookParser.php` |
| Webhook Sync UC | `app/Application/Shopwired/UseCases/Webhooks/SyncCustomerUseCase.php` |
| Webhook Delete UC | `app/Application/Shopwired/UseCases/Webhooks/DeleteCustomerUseCase.php` |
| Result DTO | `app/Application/Shopwired/DTOs/WebhookCustomerResultDTO.php` |
| Abstract Entity Job | `app/Application/Jobs/Shopwired/AbstractSyncShopwiredEntityJob.php` |
| Service Provider | `app/Providers/ShopwiredServiceProvider.php` |
| Schedule Provider | `app/Providers/Schedule/ShopwiredScheduleServiceProvider.php` |

---

## Verification

1. **API validation**: `php artisan tinker` → resolve BrandClient → `listAllBrands()` — confirms API works with embeds
2. **Migration**: `php artisan migrate` — creates both tables
3. **Sync test**: Dispatch `SyncShopwiredCategoriesJob` and `SyncShopwiredBrandsJob` manually via tinker — verify data in DB
4. **Webhook registration** (manual): Register 6 new webhook topics in ShopWired admin panel (`category.created/updated/deleted`, `brand.created/updated/deleted`) pointing to `/api/webhooks/shopwired/categories` and `/api/webhooks/shopwired/brands`
5. **Webhook test**: Use `curl` or API tool to simulate webhook payloads to the new endpoints
6. **Linting**: `make lint` passes
7. **Tests**: `make test` passes
8. **Test-ai**: `make test-ai` for mutation testing on new tests

## File Count Estimate

- ~30 new files + ~6 modified files
- Categories: ~15 new files (most layers already have templates)
- Brands: ~15 new files (parallel structure)
- Modified: Category VO, CategoryResponse, WebhookTopic (App), routes, service provider, schedule provider
