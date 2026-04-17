# View + Assembler Migration — Follow-up Plan (single PR, staged commits)

**Date:** 2026-04-18
**Context:** Follow-up to issue #588 / PR #594 (Category, Brand, Order, Customer unified on View + Assembler pattern). Product had already been migrated as the reference. This plan migrates every read site that the existing Views can serve — either directly or with a small, well-scoped View extension — so write-side VO reads stop leaking into application code. Everything below ships as **one PR, five commits** (scaffolding → mechanical swap → Reconcile → CheckExpired → dead-code sweep). Sites that would require collaborator refactors or new View types are intentionally out of scope (no deferral branch maintained).

**Guiding principles (per user direction):**
- Prefer expanding/reusing existing Views over proliferating new specialised ones. Callers may slightly over-fetch — that's fine.
- Exclude `SyncOrdersToMixpanelUseCase` (Mixpanel) — risk of subtle analytics drift too high.
- No permanent carve-outs. Anything not in scope here is explicitly *out*, not deferred with architectural narrative.
- **Propagate `IntId` as far as possible.** Where a UseCase / dispatcher / service currently takes `int` or `list<int>` but could accept `IntId` / `list<IntId>` without leaving the project's boundary, update the signature. Only convert back to `int` at genuine wire boundaries (JSON payloads going to an external API, SQL predicates operating on primary keys).
- **End with a dead-code sweep.** After the migrations land, any write-side method or field whose only remaining callers were the migrated read sites is deleted from the write-side VO. The migration must actually shrink the write-side surface, not merely duplicate it on the View.

---

## 1. Current Migration State

| Entity | View VO | Assembler | ViewModel | SQL view | Include enum |
|---|---|---|---|---|---|
| Product | `Catalog/Product/ValueObjects/ProductView` | yes | yes | `catalog.products_view` | 9 cases |
| Category | `Catalog/Category/ValueObjects/CategoryView` | yes | no | reads `shopwired.categories` directly | 4 cases |
| Brand | `Catalog/Brand/ValueObjects/BrandView` | yes | no | reads `shopwired.brands` directly | 2 cases |
| Order | `Catalog/Order/View/ValueObjects/OrderView` | yes | yes | `catalog.orders_view` | 6 fields only, no enum |
| Customer | `Customer/View/ValueObjects/CustomerView` | yes | yes | `catalog.customers_view` | 7 fields only, no enum |
| FilterGroup | no | no | no | no | — |

---

## 2. Scope — single PR, staged in 5 commits

All nine migrations (7 mechanical + Reconcile + CheckExpired) plus the dead-code sweep land on one feature branch, in the commit order below. Each commit is green on its own so the PR is bisectable.

### Commit 1 — additive scaffolding (no existing call sites touched)

Pure additions. Existing behaviour unchanged. Green with `make lint` + `make test`.

| Addition | File |
|---|---|
| `SaleSettings::fromTypedCustomFields(list<AbstractCustomFieldValue> $fields): ?self` | `app/Domain/Catalog/Product/ValueObjects/SaleSettings.php` |
| `ProductView::isInCategory(IntId $categoryId): bool` | `app/Domain/Catalog/Product/ValueObjects/ProductView.php` |
| `ProductView::getCustomField(string $key): ?AbstractCustomFieldValue` | same |
| `ProductView::hasCustomField(string $key): bool` | same |
| `ProductView::totalStock(): int` | same |
| `ProductView::allOnSaleSkus(): list<Sku>` | same |
| `ProductRepositoryInterface::findProductViewsOnSale(): list<ProductView>` | `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` |
| `EloquentProductRepository::findProductViewsOnSale()` implementation | `app/Infrastructure/Shopwired/EloquentProductRepository.php` |

**Tests added in this commit:**
- Unit tests for each new `ProductView` helper, using existing `ProductView` fixtures.
- Unit test for `SaleSettings::fromTypedCustomFields()` covering the "returns null" and "returns SaleSettings" paths.
- Feature test for `findProductViewsOnSale()` asserting only active on-sale products are returned.

**Design note — keep the View typed.** `SaleSettings::fromTypedCustomFields()` is added rather than exposing `$rawCustomFields` on `ProductView`. The View holds `list<AbstractCustomFieldValue>` by design; leaking the raw array would invite other callers to skip the typed path.

### Commit 2 — mechanical 7-site swap

No new fields. Pure substitution of `getProduct()` / `findByExternalId()` with the corresponding `View` call.

| # | File | Line | Current call | Migrate to | Field(s) used |
|---|---|---|---|---|---|
| 1 | `app/Infrastructure/Jobs/Shopwired/UpdateShopwiredAddToSaleJob.php` | 57 | `productRepo->getProduct($id)->hasAnySaleActive()` | `productRepo->findProductView(new ProductDetailQueryParams($id))->hasAnySale` | `hasAnySale` |
| 2 | `app/Infrastructure/Jobs/Shopwired/UpdateShopwiredRemoveFromSaleJob.php` | 57 | same | same | `hasAnySale` |
| 3 | `app/Application/Shopwired/SaleManagement/UseCases/AddProductToSaleUseCase.php` | 57 | `getProduct($id)` | `findProductView(new ProductDetailQueryParams($id))` | `sortOrder`, `categoryIds` |
| 4 | `app/Application/Shopwired/SaleManagement/UseCases/RemoveProductFromSaleUseCase.php` | 51 | `getProduct($id)` | same | `sortOrder`, `categoryIds` |
| 5 | `app/Application/Shopwired/CategoryMembership/UseCases/UpdateProductCategoryMembershipUseCase.php` | 49 | `getProduct($id)` | same | `categoryIds` |
| 6 | `app/Infrastructure/Notifications/Listeners/ProductPricingUpdatedSlackListener.php` | 70 | `getProduct($id)->url` / `->title` | `findProductView(...)->links->publicUrl` / `->title` | `title`, `links.publicUrl` |
| 7 | `app/Application/Catalog/UseCases/SyncBestSellersCategoryUseCase.php` | 64 | `categoryRepo->findByExternalId($id)` + null-check → throw `ResourceNotFoundException` | `categoryRepo->findCategoryForApi($id)` (repo throws `ResourceNotFoundException` itself; use case only still checks `$active`) | `active` |

**Semantic equivalence (verified):**
- `ProductView::$hasAnySale` is assembler-computed as `$this->isOnSale || $hasAnyVariationOnSale` using the same `isSaleActive()` logic as write-side `Product::hasAnySaleActive()`. Safe swap.
- `ProductLinks::$publicUrl` carries the same public URL as write-side `Product::$url`. Access path changes (`->url` → `->links->publicUrl`) but the value is identical.
- `findCategoryForApi(IntId)` throws `ResourceNotFoundException` on miss — exactly the case `SyncBestSellersCategoryUseCase` already manually converts from a null. Post-migration the use case gets shorter.

**IntId propagation (sites #3, #4, #5):** `ProductView::$categoryIds` is typed `list<IntId>`. Rather than converting to `list<int>` at the call site, push `IntId` downstream through every collaborator the UseCases call:

- `AddProductToSaleUseCase` / `RemoveProductFromSaleUseCase` — their `sortOrder` parameter is already a primitive `int` flowing to a dispatcher; update the dispatcher interface (`SaleReconciliationDispatcherInterface` and siblings) to accept `IntId` where it represents an external ID, and `list<IntId>` for any `categoryIds` parameter passed through.
- `UpdateProductCategoryMembershipUseCase` — the category-diff operation compares the View's `list<IntId>` against the incoming payload's category list. Update the incoming payload's type in the Command / request shape to `list<IntId>` too. The Command is our code, not a wire boundary.
- Conversion back to `int` happens **only** at: (a) the final HTTP request body going to ShopWired's API (Infrastructure `Request` class), and (b) the SQL `where('external_id', $id->value)` predicates inside `EloquentProductRepository`.

Do NOT add a `list<IntId> → list<int>` shorthand on `ProductView`. The View stays typed; conversion lives in the outermost wire-boundary class.

**Test changes:** every test covering these 7 sites has mocks on `->getProduct(...)` or `->findByExternalId(...)` returning write-side VO fakes. Swap to `ProductView` / `CategoryView` fakes using the existing fixtures. Dispatcher and Command test doubles updated to the new `IntId` signatures.

### Commit 3 — `ReconcileProductSaleStateUseCase` migration

Depends on commit 1 (needs `SaleSettings::fromTypedCustomFields` + `ProductView::isInCategory`).

**Changes:**
1. `ProductSaleStateResolver::evaluate()` signature: `Product` → `ProductView`. Only caller is this use case; refactor is localised.
2. In the resolver, swap `$product->hasAnySaleActive()` → `$view->hasAnySale` and `$product->isInCategory(...)` → `$view->isInCategory(...)`.
3. In the use case: `$this->productRepo->getProduct($productId)` → `$this->productRepo->findProductView(new ProductDetailQueryParams($productId))`.
4. `buildSaleSettingsFromProduct()` becomes `buildSaleSettingsFromView()`: `SaleSettings::fromRawCustomFields($product->rawCustomFields)` → `SaleSettings::fromTypedCustomFields($view->customFields)`.

**Tests updated:** `ReconcileProductSaleStateUseCaseTest` mocks `findProductView` instead of `getProduct` and returns `ProductView` fakes. `ProductSaleStateResolverTest` swaps its input fixture from `Product` to `ProductView`.

### Commit 4 — `CheckExpiredSalesUseCase` migration

Depends on commit 1 (needs the four `ProductView` helpers + `findProductViewsOnSale`).

**Changes:**
1. `$products = $this->productRepository->getProductsOnSale()` → `$views = $this->productRepository->findProductViewsOnSale()`.
2. Replace per-item reads:
   - `$product->getCustomField(...)` → `$view->getCustomField(...)`
   - `$product->hasCustomField('discontinued')` → `$view->hasCustomField('discontinued')`
   - `$product->totalStock()` → `$view->totalStock()`
   - `$product->allOnSaleSkus()` → `$view->allOnSaleSkus()`
   - `$product->isActive` / `$product->id` → identical fields on `ProductView`.

**Tests updated:** `CheckExpiredSalesUseCaseTest` swaps its mock return shape to `list<ProductView>`. Test fixture helper that built `Product` fakes is replaced with the ProductView fixture helper introduced in commit 1.

### Commit 5 — dead-code sweep on write-side entities

Runs after every read-site migration has landed. Each candidate below is only deleted if a repo-wide grep returns **zero** remaining callers outside tests (and any remaining test-only callers are also removed).

**Candidates on `Product`** (write-side VO `app/Domain/Catalog/Product/ValueObjects/Product.php`):

| Symbol | Replaced by | Verification |
|---|---|---|
| `Product::hasAnySaleActive(): bool` | `ProductView::$hasAnySale` | Grep `hasAnySaleActive` — expect hits only inside `Product` itself and its unit test |
| `Product::isInCategory(IntId): bool` | `ProductView::isInCategory(IntId)` | Grep `->isInCategory(` across `app/` |
| `Product::getCustomField(string)` | `ProductView::getCustomField(string)` | Grep `->getCustomField(` |
| `Product::hasCustomField(string)` | `ProductView::hasCustomField(string)` | Grep `->hasCustomField(` |
| `Product::totalStock(): int` | `ProductView::totalStock()` | Grep `->totalStock(` |
| `Product::allOnSaleSkus(): list<Sku>` | `ProductView::allOnSaleSkus()` | Grep `->allOnSaleSkus(` |
| `Product::$url` | `ProductView::$links->publicUrl` | Grep `->url` in contexts where `$receiver instanceof Product` — trickier, confirm by reviewing every remaining `Product` usage |
| `Product::$rawCustomFields` | `ProductView::$customFields` (typed) + `SaleSettings::fromTypedCustomFields()` | Grep `->rawCustomFields` and `fromRawCustomFields` — if both have no callers, delete `SaleSettings::fromRawCustomFields()` too |

**Candidates on `Category`** (write-side VO and the repository method):

| Symbol | Replaced by | Verification |
|---|---|---|
| `CategoryRepositoryInterface::findByExternalId(int): ?Category` | `findCategoryForApi(IntId): CategoryView` | Grep `findByExternalId(` on the category repo — if the only remaining caller was site #7, the method can be deleted (and its Eloquent implementation). If webhook upsert paths or other write-side code still use it, leave it. |

**Rules of the sweep:**
1. For each symbol, run the grep, read every remaining hit in context, and only delete if genuinely unreferenced.
2. Tests that exist *only* to exercise the deleted symbol are deleted alongside the symbol. Tests that exercise broader behaviour but happen to touch the symbol are updated to the View path.
3. If a symbol has even one legitimate remaining caller, leave it — and note in the PR description which caller kept it alive.
4. `make lint && make test` must stay green after the deletions.

**No new write-side methods introduced in this commit** — this is purely removal + test cleanup.

---

## 3. Out of scope (explicit)

- **`SyncOrdersToMixpanelUseCase`** — excluded per user direction. Mixpanel payload drift risk is too high for a mechanical swap; `OrderView` is also too slim.
- **`UpdateProductSellingPricesUseCase`, `BasicProductUpdateClient`, `ShopwiredAuditOrderSyncCommand`** — not migrated here. They require either collaborator refactors or new View types beyond the single-PR scope agreed above. If they become a priority, raise them as their own issue.

---

## 4. Optional follow-up workstream (separate PR, not part of this plan)

**Finish Category/Brand pattern parity.** `CategoryViewAssembler::toViewDomain(CategoryModel, …)` runs against the **write-side** `CategoryModel`. Product, Order and Customer all have dedicated `*ViewModel` reading from a `catalog.*_view` SQL view. Closing this gap unlocks pre-computed SQL columns and uniform read-replica/cache tooling, but nothing is broken today. Independent of §2.

Shape if picked up later:
1. Migration creating `catalog.categories_view` and `catalog.brands_view` (passthrough).
2. Add `Infrastructure/Catalog/Category/Models/CategoryViewModel.php` and `Brand/Models/BrandViewModel.php`.
3. Repoint `EloquentCategoryRepository::findCategoryForApi()` / `paginate()` and `EloquentBrandRepository` equivalents at the new ViewModels.
4. Write-side repository methods (webhook upserts, etc.) continue using existing `CategoryModel` / `BrandModel`.

---

## 5. Critical files

- `app/Domain/Catalog/Product/ValueObjects/ProductView.php` — gains 5 helper methods (commit 1)
- `app/Domain/Catalog/Product/ValueObjects/ProductLinks.php` — `$publicUrl` swap target (commit 2, site #6)
- `app/Domain/Catalog/Product/ValueObjects/SaleSettings.php` — gains `fromTypedCustomFields()` (commit 1)
- `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` — gains `findProductViewsOnSale()` (commit 1); `findProductView()` is the commit-2 target
- `app/Application/Contracts/Shopwired/CategoryRepositoryInterface.php` — `findCategoryForApi()` is the commit-2 target for site #7
- `app/Infrastructure/Shopwired/EloquentProductRepository.php` — implements the new repo method (commit 1)
- `app/Application/Catalog/Queries/ProductDetailQueryParams.php` — constructor shape for commit 2 sites
- `app/Application/Shopwired/SaleManagement/Resolvers/ProductSaleStateResolver.php` — signature change (commit 3)
- `app/Application/Shopwired/SaleManagement/UseCases/ReconcileProductSaleStateUseCase.php` — commit 3
- `app/Application/Shopwired/SaleManagement/UseCases/CheckExpiredSalesUseCase.php` — commit 4
- `app/Domain/Catalog/Product/ValueObjects/Product.php` — commit 5 (dead-code sweep target)
- `app/Application/Contracts/Shopwired/SaleReconciliationDispatcherInterface.php` — signatures updated to `IntId` in commit 2
- `.ai/plans/2026-04-17_588-migrate-category-brand-order-customer-view-assembler.md` — prior plan; reference for naming/placement conventions

---

## 6. Verification — end of PR, before merge

1. `make lint` — Pint, PHPStan, PHPArkitect, Deptrac, TLint all green at the PR tip. Deptrac specifically catches a View reaching into write-side territory.
2. `make test` — all migrated sites' tests pass against View mocks; new tests from commit 1 pass.
3. Bisectability spot-check: `git checkout <commit N>` for N = 1..4, `make lint && make test` → green. Commit 5 is tip.
4. Manual spot-check of the two job middlewares (`UpdateShopwiredAddToSaleJob`, `UpdateShopwiredRemoveFromSaleJob`): dispatch via tinker against a known on-sale product and confirm the `Skip::when` guard fires or skips identically to pre-migration.
5. Manual Slack listener check: trigger a `ProductPricingUpdatedEvent` for a known product and confirm the Slack notification still shows title + URL.
6. Confirm `SyncBestSellersCategoryUseCase` still throws `ResourceNotFoundException` for both the "no such category" and "category inactive" cases.
7. Smoke-test `ReconcileProductSaleStateUseCase` via tinker against a known-drifting product — confirm it still dispatches the same correction jobs as pre-migration.
8. Smoke-test `CheckExpiredSalesUseCase` via tinker in a dry-run mode (if one exists) — confirm the set of expired products identified is identical to a pre-migration run on the same dataset.
9. **Dead-code sweep verification**: for each symbol deleted in commit 5, do one final repo-wide grep on `main` (or the latest `develop` tip) to confirm zero callers remain. Record each confirmed-dead symbol in the PR description.
