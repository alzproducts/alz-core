# Plan: Migrate Category, Brand, Order, Customer to the View + Assembler pattern

## Context

The Product domain was recently refactored onto a three-part read pattern:

1. **Domain `ProductView`** — slim, `readonly` VO that self-constructs typed domain values (`IntId`, `Money`, `Sku`) from primitives, distinct from the write-side `Product` VO.
2. **Infrastructure `ProductViewAssembler`** — dedicated class that orchestrates includes/relations and calls `new ProductView(...)`. The Eloquent model has *no* `toViewDomain()` method.
3. **Infrastructure `ProductViewModel`** — Eloquent model backed by a Postgres SQL view (`catalog.products_view`) that pre-computes derived columns.

Orbiting that core are: a **Domain-layer `ProductInclude` enum**, typed `list<ProductInclude>` signatures end-to-end (dropping to `string` only at the HTTP boundary), and a `GetProductResult` wrapper (View + requested includes) consumed by the Resource.

The other four catalog-adjacent entities sit in three different states:

- **Category / Brand** — *partially* aligned. They already have `CategoryView` / `BrandView` VOs (correctly slim, `IntId`-typed, conditional nullable includes), but: (a) construction happens inline in `CategoryModel::toViewDomain()` / `BrandModel::toViewDomain()` rather than a dedicated Assembler, and (b) the Include enums live in `App\Presentation\Http\Api\Enums\` instead of `App\Domain\…\Enums\` like `ProductInclude` does.
- **Order** — no read projection exists. `Order` is a richly-typed write VO used by sync/webhooks; no consumer API, no SQL view, no Assembler.
- **Customer** — same as Order; only webhook ingress exists today.

Goal: bring all four onto the Product pattern so later enrichment (denormalised view columns, richer embeds, consumer endpoints) has a uniform extension point. Even where the Assembler is currently a trivial 1:1 mapping, establishing the class now means subsequent work (inventory joins, computed flags, supplier linkage) slots in without changing callers.

The frontend audit at `.ai/reports/audit-20260416_category-brand-frontend-field-usage.md` confirms that the Category/Brand Views already expose everything the frontend reads; no field changes are needed to their JSON shape. Order/Customer have no consumer API yet, so the new Views ship as scaffolding (minimum-core fields; no routes, Resources, or UseCases in this plan).

## Decisions baked in (from clarifying Qs + /check reviews)

- **Category/Brand**: Full alignment — extract Assemblers, move Include enums to Domain. Keep the existing `GetCategoryResult` / `GetBrandResult` wrappers (Product uses an equivalent `GetProductResult`, so this is *not* a divergence).
- **Order/Customer**: Views + Assemblers only. No new routes, Resources, UseCases, or RequestDTOs.
- **Directory split (Infrastructure)**: **Only new View-side** classes go under `Infrastructure/Catalog/{Category,Brand,Order}/` (and `Infrastructure/Customer/` for the non-catalog entity). **Write-side stays put** in `Infrastructure/Shopwired/` — `CategoryModel`, `BrandModel`, `OrderModel`, `CustomerModel`, `OrderModelMapper`, `CustomerModelMapper`, status mappers, and the Eloquent repositories are integration-layer write-path code and don't move. This matches the Product precedent: `ProductViewAssembler` and `ProductViewModel` live in `Infrastructure/Catalog/Product/`, but the write-side `ProductModelMapper` and sync-facing repository stay in `Infrastructure/Shopwired/`. Cross-directory imports (new assembler → existing ShopWired model) are already the norm.
- **Directory convention (Domain)**: New Order/Customer Views live in a dedicated `View/` subdirectory inside each Domain entity (`Domain/Catalog/Order/View/`, `Domain/Customer/View/`). Rationale: `Product/ValueObjects/` already holds 23 files and `Order/ValueObjects/` holds 16 — a dedicated `View/` dir keeps read-side projections separate from write-side VOs and prevents further clutter as embeds land. Category/Brand Views stay in `ValueObjects/` for now (single-file projections; cheap to move later if they grow). `ProductView` also stays — deferred migration to avoid churning the reference class in this pass.
- **Convention note (Catalog)**: `Domain/Catalog/CLAUDE.md` says "Assemblers orchestrate — they don't construct VOs field-by-field". This targets assemblers *building typed domain values* inline. Since our Views self-construct `IntId`/`Money` from primitives, Assembler-side `new ProductView(...)`/`new CategoryView(...)` calls comply with the convention (confirmed by `ProductViewAssembler::toViewDomain()` doing the same).
- **SQL views**: Create `shopwired.orders_view` **built on `shopwired.orders_deduplicated`** (which already applies `DISTINCT ON (reference)` — see `database/migrations/2026_02_01_100001_create_orders_deduplicated_view_shopwired.php`) and `shopwired.customers_view` (1:1 passthrough). Category/Brand continue reading from `shopwired.categories` / `shopwired.brands` directly — no SQL view.
- **Include trait**: `ValidatesIncludesTrait` stays unchanged — keeps returning `list<string>`. Each RequestDTO converts string→enum inside its own `toQuery()` (or equivalent) via `array_map(CategoryInclude::fromValue(...), …)`, mirroring how `ProductRequestDTO` does it. Avoids rippling the change through Product.
- **Money precision**: `shopwired.orders.total` is `decimal(14, 6)` — six decimal places. To preserve that precision end-to-end, the Eloquent cast is `decimal:6` (string passthrough) and a new `Money::inclusiveFromString(string $amount, string $currency = 'GBP')` factory is added (see §2.5). The existing `Money::inclusive(float, …)` path is unsafe for 6-dp values because the assembler→Money hop forces a string→float coercion that silently truncates. `decimal(14,6)` max of 99,999,999.999999 does fit within `float`'s ~15 sig digits in practice, but the string factory removes the risk entirely and keeps Money in charge of numeric parsing.
- **Initial slim shape**:
  - `OrderView`: `id` (IntId), `reference` (int), `placedAt` (DateTimeImmutable), `total` (Money, inclusive — constructed via new `Money::inclusiveFromString()` factory; see §2.5), `status` (reuse `OrderStatus` VO), `customer` (new slim `OrderCustomerSummary` with `email` + `fullName`).
  - `OrderCustomerSummary`: readonly nested VO in `Domain/Catalog/Order/View/` — **NOT** to be confused with the existing write-side `OrderCustomer` in `ValueObjects/` (that one carries id, type, dateOfBirth, deviceInfo — irrelevant for the slim View). Named `…Summary` for clarity in imports.
  - `CustomerView`: `id` (IntId), `email`, `firstName`, `lastName`, `isTrade`, `isActive`, `createdAt`; exposes `fullName()` helper (copied from write-side `Customer`).

---

## Workstream 1 — Category / Brand alignment

### 1.1 Move Include enums from Presentation to Domain

Create the Domain-layer enums modelled exactly on `app/Domain/Catalog/Product/Enums/ProductInclude.php` (string-backed, `values()` method, `fromValue()` method throwing `InvalidEnumValueException`):

- `app/Domain/Catalog/Category/Enums/CategoryInclude.php` — cases `Description`, `Description2`, `ParentIds`, `CustomFields`.
- `app/Domain/Catalog/Brand/Enums/BrandInclude.php` — cases `Description`, `CustomFields`.

Then **delete** `app/Presentation/Http/Api/Enums/CategoryIncludeEnum.php` and `app/Presentation/Http/Api/Enums/BrandIncludeEnum.php`.

Update all call sites (grep for `CategoryIncludeEnum` / `BrandIncludeEnum`):

- `app/Presentation/Http/Api/DTOs/ListCategoriesRequestDTO.php`
- `app/Presentation/Http/Api/DTOs/ShowCategoryRequestDTO.php`
- `app/Presentation/Http/Api/Resources/CategoryDetailResource.php` — swap `hasInclude(CategoryIncludeEnum::Description->value)` to accept the typed enum (see §1.3).
- Same three files for Brand.

### 1.2 Extract Assemblers

Create, modelled on `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`:

- `app/Infrastructure/Catalog/Category/Mappers/CategoryViewAssembler.php`
- `app/Infrastructure/Catalog/Brand/Mappers/BrandViewAssembler.php`

Signature for each:

```php
final readonly class CategoryViewAssembler
{
    public function __construct(private CustomFieldFactory $customFieldFactory) {}

    /** @param list<CategoryInclude> $includes */
    public function toViewDomain(CategoryModel $model, array $includes = []): CategoryView
    { /* moved verbatim from CategoryModel::toViewDomain, adapted to enum checks */ }
}
```

Move the logic currently in `CategoryModel::toViewDomain()` (lines 125–157) into the new assembler. Change include checks from `\in_array('description', $includes, true)` to `\in_array(CategoryInclude::Description, $includes, true)`.

Then **delete** `CategoryModel::toViewDomain()` and `BrandModel::toViewDomain()` — the Assembler is the single entry point (matches Product, whose Eloquent view model has no `toViewDomain`).

Also update `CategoryView` / `BrandView` class docblocks — the line `Constructed by CategoryModel::toViewDomain().` (CategoryView.php:18) goes stale after extraction. Change to `Constructed by CategoryViewAssembler.` (and the Brand equivalent).

### 1.3 Re-wire repositories, UseCases, Resources for typed includes

`EloquentCategoryRepository` (and `EloquentBrandRepository`):

- Inject the new assembler into the constructor alongside `CustomFieldFactory` — drop the `CustomFieldFactory` private property because it becomes an internal detail of the assembler.
- Replace `mapper: fn(CategoryModel $model): CategoryView => $model->toViewDomain($params->includes, $this->customFieldFactory)` (lines 173 and 196 of `EloquentCategoryRepository`) with `mapper: fn(CategoryModel $model): CategoryView => $this->assembler->toViewDomain($model, $includes)`.
- Change `findCategoryForApi(IntId $categoryId, array $includes = [])` and `CategoryListQueryParams::$includes` to typed `list<CategoryInclude>` (matches Product, where `ProductDetailQueryParams::$includes` is typed). Update the `CategoryListQueryParams` property docblock (`@param list<string>` → `@param list<CategoryInclude>`).

`GetCategoryUseCase` / `ListCategoriesUseCase`: update `$includes` parameter types from `list<string>` to `list<CategoryInclude>`. `GetCategoryResult::hasInclude()` changes from `string $name` to `CategoryInclude $include`.

`CategoryDetailResource::conditionalIncludes()`: change comparisons from `->hasInclude(CategoryIncludeEnum::Description->value)` to `->hasInclude(CategoryInclude::Description)` (the wrapper now takes the typed enum).

`ListCategoriesRequestDTO` / `ShowCategoryRequestDTO` → inside `toQuery()` / equivalent, convert the incoming `list<string>` (from `validatedIncludes()`) to `list<CategoryInclude>` via `array_map(CategoryInclude::fromValue(...), $this->validatedIncludes())`. **Do not change `ValidatesIncludesTrait`** — it keeps returning `list<string>`; conversion is per-DTO. This mirrors how Product does it and leaves Product DTOs untouched.

`ValidatesIncludesTrait::allowedIncludes()` implementations on Category/Brand DTOs: return `CategoryInclude::values()` / `BrandInclude::values()` (trait signature stays `list<string>`; the enum's `values()` already returns that).

Do the equivalent edits for the Brand pipeline.

### 1.4 Update secondary call sites (added per /check review)

Two use cases currently pass **string literals** to `findCategoryForApi` / `findBrandForApi` — they break when the signature becomes `list<CategoryInclude>` / `list<BrandInclude>`. Update both:

- `app/Application/Catalog/UseCases/GetCategoryCustomFieldsUseCase.php` line ~53: `['custom_fields']` → `[CategoryInclude::CustomFields]`.
- `app/Application/Catalog/UseCases/GetBrandCustomFieldsUseCase.php` equivalent line: `['custom_fields']` → `[BrandInclude::CustomFields]`.

Verification: `Grep "findCategoryForApi\|findBrandForApi"` after the change — zero `['...']` string-array call sites remain.

### 1.5 Tests

Update tests that reference the moved enums, `Model::toViewDomain()`, or the typed repository signatures:

- `tests/Feature/Presentation/Http/Api/Controllers/CategoryControllerTest.php` — swap `CategoryIncludeEnum` references.
- `tests/Feature/Presentation/Http/Api/Controllers/BrandControllerTest.php` — same.
- `tests/Unit/Application/Catalog/UseCases/GetCategoryUseCaseTest.php` / `GetBrandUseCaseTest.php` — each has ≥3 `findCategoryForApi`/`findBrandForApi` mock setups passing `list<string>`. Migrate all of them to `list<CategoryInclude>` / `list<BrandInclude>`.
- `tests/Unit/Application/Catalog/UseCases/GetCategoryCustomFieldsUseCaseTest.php` / `GetBrandCustomFieldsUseCaseTest.php` — update mock expectations for the new typed arg.

(Verified: no existing direct-unit tests for `CategoryModel::toViewDomain` / `BrandModel::toViewDomain` — Grep of `tests/` returns zero matches. Nothing to migrate.)

---

## Workstream 2 — Order View + Assembler + SQL view

### 2.1 Slim Domain VOs — in new `View/` subdirectory

Directory: `app/Domain/Catalog/Order/View/` (new).

- `app/Domain/Catalog/Order/View/OrderView.php` — fields: `IntId $id`, `int $reference`, `DateTimeImmutable $placedAt`, `Money $total` (inclusive — constructor takes `string $total` and calls `Money::inclusiveFromString($total)` internally so precision is preserved from the DB string all the way to Money), `OrderStatus $status` *(reuse existing VO at `app/Domain/Catalog/Order/ValueObjects/OrderStatus.php`)*, `OrderCustomerSummary $customer`. Self-constructs `IntId` from the raw `int $externalId` constructor arg, mirroring `ProductView`'s pattern (lines 103–162).
- `app/Domain/Catalog/Order/View/OrderCustomerSummary.php` — readonly VO with `public string $email`, `public string $fullName`. **Distinct** from the existing write-side `OrderCustomer` in `ValueObjects/` (that one carries id, type, dateOfBirth, deviceInfo — irrelevant for the slim View). Named `…Summary` to avoid confusion in imports.

No OrderInclude enum in this pass: the slim View has no conditional fields. Add the enum in a follow-up when the first embed lands (likely `Products` or `CustomFields`).

### 2.2 SQL view + Eloquent ViewModel

- Migration: `database/migrations/{ts}_create_shopwired_orders_view.php`. Schema name in filename per `database/CLAUDE.md` naming convention.
- View body:

  ```sql
  CREATE OR REPLACE VIEW shopwired.orders_view AS
  SELECT
      id,
      external_id,
      reference,
      order_placed_at AS placed_at,
      total,
      status_id,
      status_name,
      status_type,
      status_sort_order,
      lifecycle_status,
      billing_email,
      billing_name,
      customer_id
  FROM shopwired.orders_deduplicated;
  ```

  Rationale: `orders_deduplicated` already dedupes edited-order duplicates (per `database/CLAUDE.md` Order Deduplication section). Column names reflect the **actual** `shopwired.orders` schema (see `database/migrations/2026_01_11_033928_create_shopwired_orders_table.php` + `2026_01_12_124157_add_status_sort_order_to_shopwired_orders.php`): `status_name` (not `status_label`), `billing_email` (not `customer_email`), `billing_name` (not `customer_first_name`/`_last_name`). **All four status columns** (`status_id`, `status_name`, `status_type`, `status_sort_order`) are projected because `OrderStatus` is a 4-field VO (`{id, name: OrderStatusType, type: string, sortOrder: int}`) — see `OrderModelMapper.php:221-231` for the canonical reconstruction path. `lifecycle_status` is kept for future enrichment. The Assembler builds `OrderCustomerSummary` directly from `billing_email` + `billing_name`. If future work needs a fallback to `delivery_email`, promote the `COALESCE` into the view definition.

- `app/Infrastructure/Catalog/Order/Models/OrderViewModel.php` — Eloquent model, `$table = 'shopwired.orders_view'`, `$timestamps = false`, schema-qualified name, read-only (no write methods). Mirrors `ProductViewModel` structure. Casts: `placed_at` → `immutable_datetime`, `total` → `decimal:6` (string passthrough — Laravel's `decimal:n` cast returns a string, matching `shopwired.orders.total` as `decimal(14, 6)`). The Assembler reads `$model->total` as `string` and hands it to `Money::inclusiveFromString()` — see §2.5.

### 2.3 Assembler

- `app/Infrastructure/Catalog/Order/Mappers/OrderViewAssembler.php` — `public function toViewDomain(OrderViewModel $model): OrderView`.
- `OrderStatus` reconstruction: **reuse `MapperHelperTrait::buildEnum()`** (see `app/Infrastructure/Concerns/MapperHelperTrait.php`; already used by `OrderModelMapper`). `buildEnum()` returns a single `BackedEnum` (for `name: OrderStatusType`); the full `OrderStatus` VO is then assembled from the 4 projected columns:

  ```php
  new OrderStatus(
      id: $model->status_id,
      name: MapperHelperTrait::buildEnum(OrderStatusType::class, $model->status_name, OrderStatusType::Processing, $model->external_id, 'status_name'),
      type: $model->status_type,
      sortOrder: $model->status_sort_order ?? 0,
  );
  ```

  This is the same pattern `OrderModelMapper.php:221-231` already uses — **extract into a shared private static helper** (`buildOrderStatus(OrderViewModel|OrderModel $model): OrderStatus`) to avoid drift when ShopWired adds new status types.
- Builds `OrderCustomerSummary` from `billing_email` + `billing_name` (single-field name; use as `fullName` directly).

### 2.5 Extend `Money` with a string factory (precision-preserving)

Add one method to `app/Domain/Shared/Money/ValueObjects/Money.php`:

```php
public static function inclusiveFromString(string $amount, string $currency = 'GBP'): self
{
    // Parse to internal representation without lossy float coercion.
    // Implementation can delegate to brick/money's Money::of($amount, $currency, …)
    // or parse into minor units directly — whichever matches the existing
    // Money internals. The key contract: no intermediate float.
    return new self(/* … from string … */, TaxType::Inclusive);
}
```

Why a new factory rather than widening `inclusive(float|string)`:
- Keeps the existing `inclusive()` signature stable (zero risk to current callers).
- Makes the precision-preserving path explicit at every call site — easy to audit.
- Mirrors how `Money::fromTaxType()` already distinguishes its entry contract.

No other `Money` methods change in this pass. Unit tests for the new factory live under `tests/Unit/Domain/Shared/Money/` per `tests/TestingStrategy.md` — Domain-layer Money is the one place where unit tests *are* the right convention (90% coverage target, 85%+ MSI).

### 2.6 Tests

Per `tests/TestingStrategy.md` Infrastructure policy — **no Assembler unit tests**. Infrastructure layer uses integration tests at boundaries; internal mapping/glue code is excluded (ProductViewAssembler has none, which is the established convention). The new Assembler is covered by:

1. **Domain unit test** for `Money::inclusiveFromString()` (the one genuinely testable unit introduced) — see §2.5.
2. **Tinker smoke test** in §Verification — constructs an `OrderView` against live data to prove the assembly path works end-to-end.
3. **Future feature test** — when the first consumer endpoint lands (out of scope for this plan), a feature test will exercise the Assembler naturally via the controller→UseCase→repository path, matching how `CategoryControllerTest` covers `CategoryViewAssembler` today.

---

## Workstream 3 — Customer View + Assembler + SQL view

### 3.1 Slim Domain VO — in new `View/` subdirectory

Directory: `app/Domain/Customer/View/` (new).

- `app/Domain/Customer/View/CustomerView.php` — fields: `IntId $id`, `string $email`, `string $firstName`, `string $lastName`, `bool $isTrade`, `bool $isActive`, `DateTimeImmutable $createdAt`; `public function fullName(): string` helper (copy `return \mb_trim($this->firstName . ' ' . $this->lastName);` from write-side `Customer`).

Reuse existing pattern: self-construct `IntId` from `int $externalId`, mirroring `ProductView`.

### 3.2 SQL view + Eloquent ViewModel

- Migration: `database/migrations/{ts}_create_shopwired_customers_view.php`. Creates:

  ```sql
  CREATE OR REPLACE VIEW shopwired.customers_view AS
  SELECT
      id,
      external_id,
      email,
      first_name,
      last_name,
      is_trade,
      is_active,
      shopwired_created_at AS created_at
  FROM shopwired.customers;
  ```

  Column names verified against `database/migrations/2026_01_14_062157_create_shopwired_customers_table.php`.

- `app/Infrastructure/Customer/Models/CustomerViewModel.php` — Eloquent model for the view. Casts: `created_at` → `immutable_datetime`.

### 3.3 Assembler

- `app/Infrastructure/Customer/Mappers/CustomerViewAssembler.php` — `public function toViewDomain(CustomerViewModel $model): CustomerView`.

### 3.4 Tests

Per `tests/TestingStrategy.md` Infrastructure policy — **no Assembler unit tests** (same rationale as §2.6). The `CustomerView` has no dynamic conversions (all fields are plain primitives or `DateTimeImmutable`), so there's nothing unit-test-worthy in the Domain layer either. Coverage comes from:

1. The tinker smoke test in §Verification.
2. A future feature test once a consumer endpoint is added (out of scope).

---

## Critical files to modify or create

**New (Category/Brand alignment) — View-side only, placed in `Infrastructure/Catalog/*`:**
- `app/Domain/Catalog/Category/Enums/CategoryInclude.php`
- `app/Domain/Catalog/Brand/Enums/BrandInclude.php`
- `app/Infrastructure/Catalog/Category/Mappers/CategoryViewAssembler.php` — imports `CategoryModel` from `Infrastructure/Shopwired/Models/` (write-side stays put).
- `app/Infrastructure/Catalog/Brand/Mappers/BrandViewAssembler.php` — same cross-directory import pattern.

**Deleted (Category/Brand alignment):**
- `app/Presentation/Http/Api/Enums/CategoryIncludeEnum.php`
- `app/Presentation/Http/Api/Enums/BrandIncludeEnum.php`
- `CategoryModel::toViewDomain()` method (keep the class at `Infrastructure/Shopwired/Models/CategoryModel.php` — write-side stays)
- `BrandModel::toViewDomain()` method (keep the class at `Infrastructure/Shopwired/Models/BrandModel.php`)

**Edited (Order — Money factory):**
- `app/Domain/Shared/Money/ValueObjects/Money.php` — add `inclusiveFromString(string, string)` static factory (see §2.5).
- `tests/Unit/Domain/Shared/Money/MoneyTest.php` (or existing equivalent) — add unit tests for the new factory covering boundary values, max 6-dp precision, and invalid string input.

**Edited (Category/Brand alignment):**
- `app/Infrastructure/Shopwired/Repositories/EloquentCategoryRepository.php` — inject `CategoryViewAssembler`, drop `CustomFieldFactory` property, rewrite the two `$model->toViewDomain(...)` mapper closures. Stays in `Shopwired/` (sync/write path dominates its method surface).
- `app/Infrastructure/Shopwired/Repositories/EloquentBrandRepository.php` — equivalent.
- `app/Domain/Catalog/Category/ValueObjects/CategoryView.php` — docblock line 18: `Constructed by CategoryModel::toViewDomain().` → `Constructed by CategoryViewAssembler.`
- `app/Domain/Catalog/Brand/ValueObjects/BrandView.php` — same docblock fix.
- `app/Application/Catalog/UseCases/GetCategoryUseCase.php`, `ListCategoriesUseCase.php`, `GetBrandUseCase.php`, `ListBrandsUseCase.php` — include types.
- `app/Application/Catalog/UseCases/GetCategoryCustomFieldsUseCase.php`, `GetBrandCustomFieldsUseCase.php` — string-literal includes → enum cases.
- `app/Application/Catalog/UseCases/GetCategoryResult.php`, `GetBrandResult.php` — `hasInclude()` takes typed enum.
- `app/Application/Catalog/Queries/CategoryListQueryParams.php`, `BrandListQueryParams.php` — `$includes` becomes `list<CategoryInclude>` / `list<BrandInclude>` (property + docblock).
- `app/Application/Contracts/Shopwired/CategoryRepositoryInterface.php`, `BrandRepositoryInterface.php` — signature types. Namespace stays `Contracts\Shopwired` (the repository implementation lives in `Shopwired/`).
- `app/Presentation/Http/Api/DTOs/ListCategoriesRequestDTO.php`, `ShowCategoryRequestDTO.php`, `ListBrandsRequestDTO.php`, `ShowBrandRequestDTO.php` — convert string→enum inside `toQuery()`.
- `app/Presentation/Http/Api/Resources/CategoryDetailResource.php`, `BrandDetailResource.php`.

**Unchanged (but confirmed in plan):**
- `app/Presentation/Http/Api/Traits/ValidatesIncludesTrait.php` — stays `list<string>`; per-DTO conversion handles the type change.

**New (Order):**
- `app/Domain/Catalog/Order/View/OrderView.php`
- `app/Domain/Catalog/Order/View/OrderCustomerSummary.php`
- `app/Infrastructure/Catalog/Order/Models/OrderViewModel.php`
- `app/Infrastructure/Catalog/Order/Mappers/OrderViewAssembler.php`
- `database/migrations/{ts}_create_shopwired_orders_view.php`

**New (Customer):**
- `app/Domain/Customer/View/CustomerView.php`
- `app/Infrastructure/Customer/Models/CustomerViewModel.php`
- `app/Infrastructure/Customer/Mappers/CustomerViewAssembler.php`
- `database/migrations/{ts}_create_shopwired_customers_view.php`

## Existing functions/utilities to reuse

- `App\Domain\ValueObjects\IntId::from()` / `fromTrusted()` — ID wrapping (used by all Views).
- `App\Domain\Shared\Money\ValueObjects\Money` — existing factories stay unchanged; the plan adds `Money::inclusiveFromString()` (see §2.5) for precision-preserving monetary construction in `OrderView`.
- `App\Infrastructure\Shopwired\Factories\CustomFieldFactory` — already injected into category repository; becomes a dependency of the new Category/Brand Assemblers.
- `App\Infrastructure\Shopwired\ShopwiredAdminUrlResolver` — already used by `CategoryModel::toViewDomain`; moves to Assembler unchanged.
- `App\Infrastructure\Concerns\MapperHelperTrait::buildEnum()` — used by `OrderViewAssembler` for `OrderStatus` reconstruction (already used by `OrderModelMapper`; reuse rather than reimplement).
- `App\Infrastructure\Persistence\EloquentGateway::paginate()` / `findOrFail()` — Category/Brand already use this; Order/Customer will too when endpoints land.
- `App\Domain\Catalog\Order\ValueObjects\OrderStatus` (existing) — reused in `OrderView`.
- `App\Domain\Catalog\Product\Enums\ProductInclude` — **template** for `CategoryInclude`/`BrandInclude` (copy `fromValue()`, `values()` verbatim).
- `shopwired.orders_deduplicated` view (migration `2026_02_01_100001_…`) — `orders_view` builds ON TOP of this so dedup of edited orders is automatic.

## Verification

Run in order; each step should be green before proceeding.

1. **Static + style**: `make lint` — confirms Pint, PHPStan, PHPArkitect, Deptrac pass. Deptrac is the critical one: it verifies the new enums' Domain placement doesn't import anything outside Domain, and that the new Assembler classes only import allowed Infrastructure/Domain symbols.
2. **Call-site completeness**: `Grep "findCategoryForApi\|findBrandForApi"` — confirm zero call sites still pass raw `['custom_fields']` string arrays. Also `Grep "CategoryIncludeEnum\|BrandIncludeEnum"` — confirm zero matches (old enums fully removed).
3. **Unit tests**: `make test-quick` — runs the new `Money::inclusiveFromString()` Domain unit tests and the migrated `GetCategoryUseCaseTest`/`GetBrandUseCaseTest`/`GetCategoryCustomFieldsUseCaseTest`/`GetBrandCustomFieldsUseCaseTest` (typed-include arg migration). Per `tests/TestingStrategy.md`, no new Assembler unit tests are added — Infrastructure layer is tested at boundaries via feature tests.
4. **Integration**: `make test` — full suite including `CategoryControllerTest` and `BrandControllerTest`. Any feature test that hits the `?include=` parameters exercises the enum move end-to-end.
5. **Manual API smoke test** (Category/Brand, using `$API_BYPASS_SECRET`):
   ```
   curl -H "X-Local-Bypass: $API_BYPASS_SECRET" http://localhost:8000/api/categories
   curl -H "X-Local-Bypass: $API_BYPASS_SECRET" "http://localhost:8000/api/categories/123?include=description,description2,custom_fields"
   curl -H "X-Local-Bypass: $API_BYPASS_SECRET" http://localhost:8000/api/brands
   curl -H "X-Local-Bypass: $API_BYPASS_SECRET" "http://localhost:8000/api/brands/456?include=description,custom_fields"
   ```
   Expected: JSON shape identical to pre-change responses (the audit lists the exact fields the frontend reads).
6. **Migration**: `php artisan migrate` — creates `shopwired.orders_view` and `shopwired.customers_view`. Verify counts via `mcp__webstorm__execute_sql_query` or tinker:
   ```sql
   SELECT COUNT(*) FROM shopwired.orders_view;           -- should match orders_deduplicated (≤ orders)
   SELECT COUNT(*) FROM shopwired.orders_deduplicated;
   SELECT COUNT(*) FROM shopwired.customers_view;        -- should match customers
   SELECT COUNT(*) FROM shopwired.customers;
   ```
7. **Order/Customer Assembler smoke test** via `php artisan tinker`:
   ```php
   app(App\Infrastructure\Catalog\Order\Mappers\OrderViewAssembler::class)
       ->toViewDomain(App\Infrastructure\Catalog\Order\Models\OrderViewModel::first());
   app(App\Infrastructure\Customer\Mappers\CustomerViewAssembler::class)
       ->toViewDomain(App\Infrastructure\Customer\Models\CustomerViewModel::first());
   ```
   Confirms the new Views construct cleanly against real data, including `OrderStatus` reconstruction and `Money` inclusive-tax conversion.

No frontend changes are required — the Category/Brand JSON shape is preserved, and Order/Customer have no consumer surface yet.

## Explicit non-goals (for scope clarity)

- No new API endpoints for Order / Customer.
- No new `OrderResource` / `CustomerResource` / `OrderController` / `CustomerController`.
- No `OrderInclude` / `CustomerInclude` enum in this pass — the slim Views have no conditional fields. Add when the first embed is needed.
- No SQL view for Category / Brand — they continue reading from `shopwired.categories` / `shopwired.brands`.
- No changes to the write-side `Order` / `Customer` VOs or their Mappers (`OrderModelMapper`, `CustomerModelMapper`) — sync/webhook paths are untouched.
- No changes to `ValidatesIncludesTrait` — conversion happens per-DTO, keeping Product DTOs untouched.
- No move of `ProductView` / `CategoryView` / `BrandView` into `View/` subdirectories — only new Order/Customer scaffolding adopts the convention. Existing Views can be migrated later if the directory clutters.
- No changes to `GetCategoryResult` / `GetBrandResult` wrappers (Product uses the equivalent; keep for consistency).
- No unit tests for the new Assemblers — per `tests/TestingStrategy.md`, Infrastructure layer is tested at boundaries (integration/feature tests) not via unit tests on internal mapping glue. Matches the established Product precedent (`ProductViewAssembler` has zero unit tests).
- No widening of existing `Money::inclusive(float, …)` signature — the new `inclusiveFromString()` factory is additive-only, keeping the existing API stable.
