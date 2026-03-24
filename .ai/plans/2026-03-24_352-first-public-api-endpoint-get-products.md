# Plan: First Public API Endpoint — GET /api/products

## Context

Building the first consumer/frontend-facing API endpoint plus foundational infrastructure for all future API endpoints. Much of the auth/middleware stack already exists (Supabase JWT, rate limiting, RLS, MFA enforcement). This plan covers: JSON error envelope, embed/include pattern, paginated result DTO, and the products list endpoint itself.

**Confirmed decisions:**
- URL: `/api/products` (domain-centric)
- Middleware: full `auth.supabase` group (JWT + approval + RLS)
- UseCase namespace: `Application/Catalog/`
- Filter: active products only, no override for v1
- Response envelope: Laravel's `{data, meta, links}` via API Resources
- Pagination: offset-based, max 500/page, default 50
- Description field: omitted from list response (large HTML, not needed for list view)

---

## Part 1: Foundational Infrastructure (build once, reuse per endpoint)

### 1.1 — JSON Error Envelope for API Routes

**New file**: `app/Presentation/Http/Api/ApiExceptionRenderer.php`

A dedicated class that maps domain exceptions to JSON HTTP responses. Registered in `bootstrap/app.php` via a single `$exceptions->render()` call. **Must guard with `$request->expectsJson()`** — `DomainException` can also be thrown on web/feed routes where HTML responses are expected.

```
Domain Exception                        → HTTP Status
─────────────────────────────────────────────────────
ResourceNotFoundException               → 404
ValidationFailedException               → 422
TransientApiFailure                     → 503
PermanentApiFailure                     → 502
ConfigurationNotFoundException          → 500
DatabaseOperationFailedException        → 500
DomainException (catch-all)             → 500
```

Error response shape:
```json
{ "message": "Human-readable error description" }
```

Validation errors retain Laravel's default shape: `{ "message": "...", "errors": { "field": ["..."] } }`.

**Modified file**: `bootstrap/app.php` — register the renderer (one line).

### 1.2 — PaginatedList DTO

**New file**: `app/Application/DTOs/PaginatedList.php`

Framework-free paginated result. Required because Deptrac forbids Application from using `Illuminate\Pagination\LengthAwarePaginator`.

```php
/** @template T */
final readonly class PaginatedList {
    /** @param list<T> $items */
    public function __construct(
        public array $items,
        public int $total,
        public int $perPage,
        public int $currentPage,
        public int $lastPage,
    ) {}
}
```

**Why a DTO instead of LengthAwarePaginator directly?** Deptrac enforces that Application can only depend on Domain, PSR, `Illuminate\Contracts\Events`, and Webmozart Assert. `LengthAwarePaginator` is `Illuminate\Pagination` — forbidden. The DTO is reconstructed as a `LengthAwarePaginator` in the controller (Presentation layer) for Resource compatibility.

**What we preserve**: The `BuildsPaginatedResponse` trait (§1.4) reconstructs a `LengthAwarePaginator` with `->withQueryString()`, so the Resource system gets full URL generation, `meta.links`, `next_page_url`, query parameter preservation — no features lost.

### 1.3 — Include/Embed Validation

**Approach**: Embed validation integrated into a Spatie Data request DTO (§2.3), not a standalone trait. Each endpoint's Data DTO declares `allowedIncludes()` and validates `?include=` against it. This follows the project's established pattern of Spatie Data for request validation.

The include allowlist lives in the Presentation DTO. Include names are domain-level strings passed through Application to Infrastructure, where mapping to Eloquent `with()` relations happens.

### 1.4 — Paginated Response Trait

**New file**: `app/Presentation/Http/Api/Traits/BuildsPaginatedResponse.php`

Converts `PaginatedList` → `LengthAwarePaginator` → `ResourceCollection`. Handles query string preservation so pagination links include current filters/includes.

```php
trait BuildsPaginatedResponse {
    /** @param class-string<JsonResource> $resourceClass */
    protected function paginatedResponse(PaginatedList $result, string $resourceClass): ResourceCollection
    {
        $paginator = new LengthAwarePaginator(
            items: $result->items,
            total: $result->total,
            perPage: $result->perPage,
            currentPage: $result->currentPage,
        );
        $paginator->withQueryString(); // preserves ?include=, ?per_page= in links

        return $resourceClass::collection($paginator);
    }
}
```

---

## Part 2: Product List Endpoint

### 2.1 — Application Layer

**New file**: `app/Application/Catalog/UseCases/ListProductsUseCase.php`

Thin orchestrator:
- Receives `int $perPage`, `int $page`, `list<string> $includes`
- Calls `ProductRepositoryInterface::paginate()`
- Returns `PaginatedList<Product>`

Placed in `Application/Catalog/` (not `Shopwired/`) because the consumer API is domain-centric — the frontend sees "products", not "ShopWired products".

**Note**: Must propagate `@throws` from `ProductRepositoryInterface::paginate()` — `InvalidCustomFieldValueException`, `DatabaseOperationFailedException`, `ExternalServiceUnavailableException` (ShipMonk checked exception rules).

**Modified file**: `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php`

Add method:
```php
/**
 * Paginate active products with optional eager-loaded relations.
 *
 * @param list<string> $includes Relation names to eager-load (e.g., 'variations')
 * @return PaginatedList<Product>
 *
 * @throws InvalidCustomFieldValueException When custom field value type mismatches definition
 * @throws DatabaseOperationFailedException On query failure
 * @throws ExternalServiceUnavailableException When database temporarily unavailable
 */
public function paginate(int $perPage, int $page, array $includes = []): PaginatedList;
```

### 2.2 — Infrastructure Layer

**Modified file**: `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

Implement `paginate()`:
1. Query `ProductModel::query()->where('is_active', true)`
2. **Conditionally** eager-load only requested relations. Map include names → Eloquent relations: `['variations' => 'variations']`. Only eager-load what `$includes` specifies.
3. Execute `->paginate($perPage, ['*'], 'page', $page)`
4. Map each model → domain `Product` VO via **new** `ProductModelMapper::toReadDomain()` (see below)
5. Return `PaginatedList` with pagination metadata from the Laravel paginator

**Modified file**: `app/Infrastructure/Shopwired/Mappers/ProductModelMapper.php`

Add new method `toReadDomain(ProductModel $model): Product`:
- Checks `$model->relationLoaded('variations')` — if loaded, maps them; if not, passes `null` (Product VO supports this: `$variations` is `list<ProductVariation>|null` where null = "not provided")
- **Skips** `customFieldFactory->fromRawFields()` — passes empty `customFields: []`
- **Skips** `filterFactory->fromRawFilters()` — passes empty `filters: []`
- Still passes `rawCustomFields` and `rawFilters` for completeness (they're just array reads, no DB calls)
- Images mapped normally (JSONB column, always present)

**Result**: Only queries what's needed:
- `?include=variations` → 2 queries (products + variations)
- No include → 1 query (products only)
- 0 extra queries for custom field/filter registries (vs 2 with `toDomain()`)

Existing `toDomain()` is **untouched** — internal callers (sync, price updates, sale reconciliation) continue to use it with full eager-loading via `self::EAGER_LOAD_RELATIONS`.

### 2.3 — Presentation Layer

**New file**: `app/Presentation/Http/Api/DTOs/ListProductsRequestDTO.php`

Spatie LaravelData request DTO (matches project convention — Spatie Data over FormRequest):
- `per_page`: int, 1–500, default 50
- `page`: int, min 1, default 1
- `include`: nullable string (comma-separated), validated against `allowedIncludes()`
- Method: `validatedIncludes(): list<string>` — parses and returns validated include names
- Method: `allowedIncludes(): list<string>` — returns `['variations']`

Type-hinted in controller method parameter — Laravel auto-instantiates and validates.

**Existing pattern reference**: `app/Presentation/Http/Shopwired/DTOs/UpdateProductPricesDTO.php`, `app/Presentation/Http/ContactForm/DTOs/ContactFormRequestDTO.php`

**New file**: `app/Presentation/Http/Api/Resources/ProductResource.php`

- `@mixin Product` (domain VO)
- Core fields: `id`, `sku`, `gtin`, `title`, `slug`, `url`, `price`, `costPrice`, `salePrice`, `comparePrice`, `stock`, `isActive`, `vatExclusive`, `vatRelief`, `weight`, `metaTitle`, `metaDescription`, `sortOrder`
- Always included: `images` (JSONB, no extra query)
- Conditional: `variations` → `ProductVariationResource::collection()` (only when `?include=variations` requested — variations are only eager-loaded and mapped when requested, Product VO has `variations: null` otherwise)
- Dates: ISO 8601 (`DateTimeInterface::ATOM`)
- Null omission: `array_filter(fn($v) => $v !== null)` (matches existing pattern)

**New file**: `app/Presentation/Http/Api/Resources/ProductVariationResource.php`

- `@mixin ProductVariation`
- Fields: `id`, `sku`, `gtin`, `price`, `costPrice`, `salePrice`, `stock`, `weight`, `options`, `imageIndex`

**New file**: `app/Presentation/Http/Api/Controllers/ProductController.php`

- `final readonly class`
- Uses `BuildsPaginatedResponse` trait
- Constructor: injects `ListProductsUseCase`
- `index(ListProductsRequestDTO $data): ResourceCollection`

**Modified file**: `routes/api.php`

Add within a new `auth.supabase` middleware group section:
```php
Route::middleware(['auth.supabase', 'throttle:api', SentryUserContextMiddleware::class])
    ->group(static function (): void {
        Route::get('products', [ProductController::class, 'index']);
    });
```

**Middleware note**: The `auth.supabase` group includes `ValidateSupabaseJwtMiddleware` + `EnsureUserApprovedMiddleware` + `SetRlsContextMiddleware`. Existing routes use explicit middleware without the group (pre-dates group creation, YAGNI decision from Dec 2025). `SetRlsContextMiddleware` has no effect on shopwired tables (no RLS policies), but `EnsureUserApprovedMiddleware` adds a valuable approval gate. See post-checklist for security review follow-up.

---

## File Summary

| Action | File | Layer |
|--------|------|-------|
| **New** | `app/Presentation/Http/Api/ApiExceptionRenderer.php` | Presentation |
| **New** | `app/Application/DTOs/PaginatedList.php` | Application |
| **New** | `app/Presentation/Http/Api/Traits/BuildsPaginatedResponse.php` | Presentation |
| **New** | `app/Application/Catalog/UseCases/ListProductsUseCase.php` | Application |
| **New** | `app/Presentation/Http/Api/DTOs/ListProductsRequestDTO.php` | Presentation |
| **New** | `app/Presentation/Http/Api/Resources/ProductResource.php` | Presentation |
| **New** | `app/Presentation/Http/Api/Resources/ProductVariationResource.php` | Presentation |
| **New** | `app/Presentation/Http/Api/Controllers/ProductController.php` | Presentation |
| **Edit** | `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` | Application |
| **Edit** | `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` | Infrastructure |
| **Edit** | `app/Infrastructure/Shopwired/Mappers/ProductModelMapper.php` | Infrastructure |
| **Edit** | `bootstrap/app.php` | Bootstrap |
| **Edit** | `routes/api.php` | Routes |

---

## Verification

1. `make lint` — Deptrac (no Illuminate in Application), PHPArkitect (naming + layers), PHPStan, Pint
2. **Auth test**: `curl http://localhost:8000/api/products` → JSON 401 (not HTML)
3. **Approval test**: Request with valid JWT but `is_approved=false` → JSON 403
4. **Happy path**: `curl -H "Authorization: Bearer <jwt>" "http://localhost:8000/api/products?per_page=5"` → `{data: [...], meta: {current_page, last_page, per_page, total, ...}, links: {...}}`
5. **With embeds**: `?include=variations` → each product includes `variations` array
6. **Without embeds**: No `variations` key in response, no variations DB query (only 1 query for products)
7. **Invalid include**: `?include=foo` → 422 validation error
8. **Pagination bounds**: `?per_page=999` → 422; `?per_page=500` → 200
9. **Query string in links**: Pagination links preserve `?include=variations&per_page=50` via `withQueryString()`
10. **Feature tests**: HTTP test covering auth, pagination, includes, validation
11. **Unit tests**: DTO validation, Resource output shape, UseCase delegation

---

## ⚠️ Architecture Decision Required: Domain Mapper Strategy

**Must discuss before closing the issue.**

### The Problem

`ProductModelMapper::toDomain()` is an all-or-nothing mapper — it always accesses `$model->variations`, always runs `customFieldFactory->fromRawFields()`, and always runs `filterFactory->fromRawFilters()`. All existing callers eager-load variations beforehand (via `self::EAGER_LOAD_RELATIONS`), so this works.

But for the API list endpoint, this creates two inefficiencies:

1. **Variations always loaded from DB** even when `?include=variations` is not requested — 2 queries always instead of 1
2. **Custom fields and filters always typed** via factory calls (2 extra DB queries for registry lookup, per request) even though the API response doesn't expose them

For this initial endpoint, the overhead is acceptable (2+2 = 4 extra queries, factory registries cached per request). But as more endpoints are added with more embeds, this "always load everything" approach won't scale.

### The Tension

We now have two different read contexts:
- **Internal callers** (sync jobs, price updates, sale reconciliation): Need full Product VOs with all relations — the current mapper serves them well
- **API read endpoints** (list, detail): Need configurable output — only load and map what's requested

Making the mapper conditionally skip variations (e.g., `$model->relationLoaded('variations')` check) would make its output inconsistent — some callers get `variations: [...]`, others get `variations: null`. This breaks the Product VO's implicit contract that variations are always populated when loaded from DB.

### Options to Discuss

**A. Two mapper methods** — `toDomain()` (existing, full) + `toListItem()` (new, configurable)
- Pro: Clean separation, existing code untouched
- Con: Two code paths to maintain, risk of drift

**B. Product VO with nullable variations** — Change `variations` from `list<ProductVariation>` to `list<ProductVariation>|null` where null = "not loaded"
- Pro: Single mapper, explicit optionality
- Con: Every consumer of Product must handle null case

**C. Separate ProductSummary read model** — Lightweight VO for list endpoints
- Pro: Purpose-built for reads, no impact on existing Product VO
- Con: Duplication of fields, separate Resource needed

**D. Mapper accepts "include" config** — `toDomain(ProductModel $model, array $includes = [])`
- Pro: Single method, explicit control
- Con: Makes the mapper context-dependent, harder to reason about

### Current Decision

**For this PR**: Implement option **A (Two mapper methods)** — add `toReadDomain()` alongside existing `toDomain()`. The new method:
- Conditionally maps relations based on `$model->relationLoaded()`
- Skips factory calls for custom fields and filters
- Returns Product VO with `variations: null` when not loaded (VO already supports this)

This gives immediate performance benefits (1 query vs 4 when no embeds requested) while keeping the existing `toDomain()` untouched for internal callers.

**Before closing**: Discuss whether this two-method approach is the long-term strategy or whether we should evolve toward option **D (mapper accepts include config)** as more embeds are added. The risk of drift between `toDomain()` and `toReadDomain()` should be evaluated.

---

## Post-Implementation Checklist

- [ ] **Architecture decision: Domain Mapper strategy** — See section above. Decide on mapper evolution before closing the issue.
- [ ] **Endpoint security review** — Create follow-up issue to review all existing authenticated routes for consistency. Existing routes use explicit middleware (pre-dates `auth.supabase` group). Evaluate migrating HelpScout and ShopWired admin routes to `auth.supabase` group.
- [ ] **Mapper documentation** — Add docblock/CLAUDE.md note to `ProductModelMapper::toDomain()` clarifying that `variations` relation MUST be eager-loaded before calling. All existing callers follow this pattern via `self::EAGER_LOAD_RELATIONS`, but it's an implicit contract that should be documented.
- [ ] **shopwired RLS assessment** — shopwired.* tables have NO RLS policies (confirmed via migration search). All authenticated users see all rows. Evaluate if row-level restrictions are needed for shopwired data as the consumer API grows.
