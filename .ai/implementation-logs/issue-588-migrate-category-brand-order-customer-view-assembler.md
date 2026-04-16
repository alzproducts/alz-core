# Issue 588 — Migrate Category/Brand/Order/Customer to View + Assembler

**Branch:** `feature/588-migrate-category-brand-order-customer-view-assembler`
**Plan:** `.ai/plans/2026-04-17_588-migrate-category-brand-order-customer-view-assembler.md`
**Started:** 2026-04-17

## Goal

Bring Category, Brand, Order, Customer onto the same three-part read pattern Product uses (slim View VO + dedicated Assembler + optional SQL view). Uniform extension point for future enrichment.

## Workstreams

- **W1 — Category/Brand:** Extract Assemblers from Eloquent models, move Include enums from Presentation → Domain, re-wire pipeline to typed enums.
- **W2 — Order:** New `OrderView` + `OrderCustomerSummary` VOs, `OrderViewAssembler`, `OrderViewModel`, `catalog.orders_view` SQL view. Add `Money::inclusiveFromString()` factory.
- **W3 — Customer:** New `CustomerView` VO, `CustomerViewAssembler`, `CustomerViewModel`, `catalog.customers_view` SQL view.

## Decision Log

- 2026-04-17 — Starting implementation in order W1 → Money factory → W2 → W3 so tests compile incrementally and SQL migrations land together at the end.
- 2026-04-17 — `BrandListQueryParams` does not exist (plan assumed symmetry with Category). Keeping direct `paginate(perPage, page, includes, includeInactive)` signature on `BrandRepositoryInterface` — only retyping `$includes` to `list<BrandInclude>`. No scope change needed in this issue.
- 2026-04-17 — Logger context now maps enum → `->value` at the UseCase boundary so log lines stay human-readable strings. Enum types flow through everything else.
- 2026-04-17 — Did NOT extract a shared `buildOrderStatus()` helper between `OrderModelMapper` and `OrderViewAssembler`. The two models don't share an interface, union-typing the helper would add PHPStan friction, and the ~6-line pattern duplicated is smaller than the abstraction cost. Both call sites use `MapperHelperTrait::buildEnum()` directly; if ShopWired adds a new status, both paths need updating — acceptable given the rarity.
- 2026-04-17 — Order/Customer assembler bindings registered in `ShopwiredServiceProvider` rather than a new `CustomerServiceProvider`. Precedent: `CategoryViewAssembler` already lives here and the View-side Customer artifacts are fed from ShopWired sync data.
- 2026-04-17 — Initial pass: `OrderViewAssembler::buildStatus()` inlined the enum-resolution pattern to sidestep `buildEnum`'s 5-param signature and avoid a new baseline entry.
- 2026-04-17 — **Revised**: refactored `MapperHelperTrait::buildEnum()` from 5 params to 4 by introducing `EnumLogContext` VO (bundles `externalId` + `fieldName`). `OrderViewAssembler` now reuses the trait per the plan, the three existing `OrderModelMapper` call sites were updated to the new signature, and the stale `buildEnum` baseline entry was deleted. Net result: plan directive honoured, no duplication, *one* baseline entry removed rather than added.
- 2026-04-17 — `BrandViewAssembler` / `CategoryViewAssembler` decomposed: extracted `buildLinks()`, `buildImage()`, `resolveCustomFields()` (+ `resolveParentIds()` for Category) to keep `toViewDomain()` under the 20-line limit. Each helper is one coherent operation, not an arbitrary split.
- 2026-04-17 — View VOs moved into dedicated `View/ValueObjects/` sub-namespaces: `Domain/Catalog/Order/View/ValueObjects/` (OrderView, OrderCustomerSummary) and `Domain/Customer/View/ValueObjects/` (CustomerView). Reason: write-side `ValueObjects/` directories were already polluted (15 write-side VOs in Order alone); the View concern is a coherent sub-bounded-context that deserves its own slot. PHPArkitect rules unchanged — the existing taxonomy applies recursively (`View/ValueObjects/` is just a `ValueObjects/` directory under a `View/` namespace). Updated imports in `OrderViewAssembler`, `CustomerViewAssembler`, plus `@see` docblocks in the two SQL view migrations.
- 2026-04-17 — SQL views moved from `shopwired.*` to `catalog.*` schema (`catalog.orders_view`, `catalog.customers_view`) to match Product's existing `catalog.products_view` precedent. The `FROM` clauses still reference `shopwired.orders_deduplicated` / `shopwired.customers` (cross-schema reads work in PostgreSQL). Reason: schema name should describe the bounded context the view *serves* (catalog read model), not its current data origin (shopwired sync). Migration files renamed per project convention (`{action}_{schema}_{table}.php`). Both ViewModels' `$table` properties updated.

## Deviations from Plan

- Skipped `BrandListQueryParams` introduction — file didn't exist in the codebase; `ListBrandsUseCase::execute` continues with direct parameters (only `$includes` now typed as `list<BrandInclude>`).

## Progress

- [x] W1.1 Move Category/Brand Include enums to Domain
- [x] W1.2 Extract Category/Brand Assemblers; delete `Model::toViewDomain()`
- [x] W1.3 Re-wire repositories/UseCases/Resources for typed includes
- [x] W1.4 Update GetCategoryCustomFields / GetBrandCustomFields use cases
- [x] W1.5 Update Category/Brand tests
- [x] W1.6 Delete Presentation Enum files
- [x] W2.5 `Money::inclusiveFromString()` + Domain unit tests
- [x] W2.1 `OrderView` + `OrderCustomerSummary` VOs
- [x] W2.2 `catalog.orders_view` migration + `OrderViewModel`
- [x] W2.3 `OrderViewAssembler`
- [x] W3.1 `CustomerView` VO
- [x] W3.2 `shopwired.customers_view` migration + `CustomerViewModel`
- [x] W3.3 `CustomerViewAssembler`
- [x] ServiceProvider: register OrderViewAssembler + CustomerViewAssembler
- [x] Lint clean (Pint + PHPStan + PHPArkitect + Deptrac + TLint)
- [x] Tests green (3026 tests, 6948 assertions)

## PR Notes

### What
Unifies Category, Brand, Order, and Customer on the same read-side pattern Product already uses:
- Slim `*View` Domain VO (self-constructing from SQL view row primitives)
- Dedicated `*ViewAssembler` in Infrastructure
- Postgres `catalog.*_view` for Order/Customer (shape-stable, infra-owned read surface, matches Product's `catalog.products_view`)

### Why
Every future enrichment (richer embeds, consumer endpoints, denormalised view columns) now has a uniform extension point. Eloquent write-side models (`CategoryModel`, `BrandModel`, `OrderModel`) lose their read-side concerns.

### Key Decisions
- **Include enums live in Domain, not Presentation** — typed `list<CategoryInclude>` / `list<BrandInclude>` flows repository → UseCase → Result → Resource.
- **Read-side views live in `catalog.*` schema** — matches Product's existing `catalog.products_view`. Schema name describes the bounded context the view *serves*, not its data origin (so future enrichments mixing shopwired/non-shopwired sources keep the same naming).
- **`catalog.orders_view` built on `shopwired.orders_deduplicated`** — cross-schema source. Edited-order duplicates filter automatically.
- **View VOs nest under `View/ValueObjects/`** — read-side and write-side VOs separated cleanly (`Domain/Catalog/Order/View/ValueObjects/OrderView` vs `Domain/Catalog/Order/ValueObjects/Order`). PHPArkitect taxonomy applies recursively, no rule changes needed.
- **`Money::inclusiveFromString()` added** — preserves `decimal(14,6)` string precision up to the Domain boundary (skips the default 2dp rounding that `Money::inclusive()` applies).
- **Order/Customer assembler bindings in `ShopwiredServiceProvider`** — no new `CustomerServiceProvider` needed; these artifacts consume ShopWired sync data.
- **`OrderViewAssembler` reuses `MapperHelperTrait::buildEnum()`** — refactored `buildEnum` from 5 params to 4 via a new `EnumLogContext` VO so the trait fits within the project's 4-param limit. Net effect: one *fewer* PHPStan complexity baseline entry.
- **No new API endpoints for Order/Customer in this pass** — the VO + Assembler + View trio is groundwork for the next migration.

### Deviations from Plan
- `BrandListQueryParams` not introduced (file didn't exist — plan assumed symmetry with Category). `ListBrandsUseCase::execute` keeps its direct-parameter signature; only `$includes` retyped.

### Testing
- All 3026 tests pass (6948 assertions); 1502 unit tests pass locally.
- `Money::inclusiveFromString()` covered by new Domain unit tests.
- Category/Brand API response shape unchanged (Resources re-wired, not restructured).
