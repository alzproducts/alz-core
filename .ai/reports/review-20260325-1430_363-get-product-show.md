# Code Review: Issue #363 — GET /api/products/{productId} Show Endpoint

**Date:** 2026-03-25
**Branch:** `feature/363-get-product-show-endpoint-with-embeds`
**Reviewer:** Claude Opus 4.6 (Internal) + Gemini 2.5 Flash (External)
**Scope:** 50+ files, 7 commits, all 4 Clean Architecture layers

> This is a small business application. Recommendations prioritise proportional value.

---

## Executive Summary

This feature adds a `GET /api/products/{productId}` show endpoint with optional embeds (`variations`, `description`, `category_ids`, `custom_fields`, `filters`, `sale_settings`), cross-integration cost price enrichment from Linnworks, and domain value objects (`ProductView`, `ProductVariationView`, `Money`).

**Overall assessment: Well-implemented.** The code follows Clean Architecture conventions strictly, has proper Octane safety (`scoped()` bindings), comprehensive `@throws` propagation, and clean layer separation. No critical or high-severity issues found. Two medium findings relate to spec deviation and a non-harmful performance pattern. The architecture is sound and maintainable.

**Key risks:** None blocking. The two Medium findings are product/design decisions, not bugs.

---

## Findings by Severity

### Critical
None.

### High
None.

### Medium

#### M1 — `cost_price` always in base API response (spec deviation)

| Attribute | Value |
|-----------|-------|
| **Location** | `ProductResource::baseFields()` (line 55), `ProductModelMapper::toViewDomain()` (line 136) |
| **Source** | Both (Internal + External) |
| **Consensus** | Agreed — not a blocker; product decision |
| **Confidence** | Medium-High |

**Issue:** The GitHub issue spec lists `cost_price` as a conditional `?include=` embed. The implementation always loads cost prices from Linnworks (via `ProductCostPriceFactory`) and always includes `cost_price` in the base response — even when the consumer doesn't request it. `ShowProductRequestDTO::allowedIncludes()` does not list `cost_price`.

**Impact:**
- Wholesale pricing data exposed to all authenticated consumers by default
- Linnworks cost price query runs on every product API request regardless of need
- API contract differs from the issue specification

**Recommendation:** Confirm whether this is intentional. If cost_price should be conditional:
1. Add `'cost_price'` to `allowedIncludes()` in both DTOs
2. Make `getLinnworksCostPrice()` conditional on includes in `ProductModelMapper::toViewDomain()`
3. Move `cost_price` from `baseFields()` to conditional embed in `ProductDetailResource`

**Consensus note:** gemini-2.5-flash argues this is acceptable for an internal-only API (all consumers are staff behind JWT + approval). YAGNI applies if no role-based access control is planned.

---

#### M2 — Full cost price map loaded for single-product endpoint

| Attribute | Value |
|-----------|-------|
| **Location** | `ProductCostPriceFactory::getCostPrice()` (line 37), `EloquentStockItemRepository::getCostPricesBySku()` (line 117) |
| **Source** | Both (Internal + External) |
| **Consensus** | Agreed — premature optimization for small business scale |
| **Confidence** | Medium |

**Issue:** `ProductCostPriceFactory` lazy-loads ALL cost prices (full JOIN: `stock_items` + `stock_item_suppliers`) on first access, then O(1) lookups. This pattern is efficient for the paginated list endpoint (load once, lookup N). But for the show endpoint (single product), it loads the entire map just to look up 1-2 SKUs.

**Impact:** Every show endpoint request triggers a full table join. For a small catalog (hundreds/low thousands of SKUs), this is sub-100ms. For larger catalogs, it could become noticeable.

**Recommendation:** Skip. Document as known trade-off. The factory is already scoped per request (Octane-safe). Only optimize if profiling shows this is a bottleneck. A targeted `getCostPriceBySku(string $sku)` method would add interface complexity for unproven gain.

**Consensus note:** gemini-2.5-flash rates this 9/10 confidence as premature optimization. YAGNI strongly applies.

---

### Low

#### L1 — No feature tests for show endpoint

| Attribute | Value |
|-----------|-------|
| **Location** | `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php` |
| **Source** | Both (Internal + External) |
| **Confidence** | High |

**Issue:** `ProductControllerTest` has tests for the list endpoint (`index`) but none for the show endpoint (`show`). Missing coverage: happy path, 404 for non-existent product, include validation, include pass-through.

**Recommendation:** Add show endpoint tests following the existing list endpoint test patterns. Low priority per review timing (tests may be planned for a later commit).

---

#### L2 — `sale_settings` include added beyond original spec

| Attribute | Value |
|-----------|-------|
| **Location** | `ShowProductRequestDTO::allowedIncludes()` (line 42) |
| **Source** | Internal only |
| **Confidence** | Medium |

**Issue:** The issue spec lists embeds: `variations`, `description`, `cost_price`, `category_ids`, `custom_fields`, `filters`. The implementation adds `sale_settings` (not in spec) and omits `cost_price` (in spec). Added in commit `28f9df1`.

**Recommendation:** Likely intentional scope refinement. Verify with product owner and update the GitHub issue to reflect the actual API contract.

---

#### L3 — `ValidatesIncludesTrait` parses include string twice

| Attribute | Value |
|-----------|-------|
| **Location** | `ValidatesIncludesTrait::includeRules()` (line 37) + `validatedIncludes()` (line 58) |
| **Source** | Both (Internal + External) |
| **Confidence** | High |

**Issue:** The include string is exploded and mapped once during validation (closure rule) and again in `validatedIncludes()`. This is harmless (the string is small) but redundant.

**Recommendation:** Skip. The duplication is negligible. Caching the parsed result would add state to the trait, complicating the design for zero measurable benefit.

---

## Positive Findings

- **Clean Architecture compliance**: Excellent separation across all 4 layers. No layer violations detected.
- **Octane safety**: All stateful services (`ProductCostPriceFactory`, `ProductModelMapper`, `ProductVariationModelMapper`) registered as `scoped()` — fresh per request, no stale data risk.
- **`@throws` propagation**: Comprehensive and consistent from interfaces through implementations to use cases and controllers.
- **Domain value objects**: `ProductView`, `ProductVariationView`, and `Money` are well-designed readonly classes with computed properties (`profitMargin`, `isOnSale`, `hasAnySale`) computed at construction time — single source of truth.
- **`ValidatesIncludesTrait`**: Clean DRY extraction with abstract `allowedIncludes()` enforcing endpoint-specific allowlists.
- **`GetProductResult` wrapper**: Carries both product and includes list — clean decoupling of what was requested vs what's available, without leaking infrastructure concerns.
- **Exception handling**: `InternalApiExceptionMapper` correctly maps `ResourceNotFoundException` to 404 with safe user-facing messages. No internal details leaked.
- **Namespace refactoring**: Models and mappers consolidated from ShopWired-specific to cross-integration `Catalog/Product` namespace — good for long-term maintainability.

---

## Decision Audit Trail

| Step | Decision | Rationale |
|------|----------|-----------|
| Phase 1 | Review type: full | Security surface is standard input validation, not auth redesign |
| Phase 2A | 2 Medium + 3 Low findings | No Critical/High issues in any category |
| Phase 2B | External review via zen:codereview | gemini-3-pro-preview rate-limited; tool-level analysis completed |
| Phase 3A | All findings agreed between sources | No disputed findings |
| Phase 3B | Consensus: 1/3 models responded | gemini-2.5-flash (against/YAGNI) — both Mediums assessed as acceptable |
| Phase 3C | No research validation needed | Findings are codebase-specific design decisions |
| Phase 3D | No user clarification needed | Findings are clear; M1 is a product decision |
| Phase 4 | Downgraded M2 confidence to Medium | Only 1/3 consensus models responded |

**Zen tool limitation:** 2/3 external models (gemini-2.5-pro, gemini-2.0-flash) hit free-tier rate limits. Confidence levels reduced accordingly. Internal review + 1 external model + tool-level analysis still provide reasonable coverage.
