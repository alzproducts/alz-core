# Variation List Endpoint (GET /api/variations)

## Summary

Standalone API endpoint returning variations as first-class, top-level rows — analogous to `GET /api/products` but at the SKU level. Each row is self-contained with denormalized parent context.

## Architecture

- **New composition VO**: `VariationListItem` — holds a `ProductVariationView` property (composition, not flattened) plus parent context fields
- **SQL view expansion**: `catalog.product_variations_view` gains parent columns (is_active, main_category_ids, has_free_delivery, title, sku, url, etc.) to support server-side filtering
- **Assembler**: New `VariationListAssembler` composes variation view models + parent data into `VariationListItem` domain VOs
- **API Resource**: New `VariationListResource` serializes `VariationListItem` for the response

## Confirmed Field List

### Core variation data (from ProductVariationView via composition)

| Field | Type | Notes |
|-------|------|-------|
| id | IntId | Variation external ID |
| sku | ?Sku | Variation SKU (nullable for legacy) |
| gtin | ?Gtin | Variation GTIN |
| mpn | ?string | Manufacturer Part Number |
| price | Money | Variation selling price |
| costPrice | ?Money | Variation cost price |
| salePrice | ?Money | Variation discounted price |
| rrp | ?Money | Variation RRP |
| effectivePrice | Money | Selling price after sale logic |
| profitMargin | ?float | Margin % |
| isOnSale | bool | Variation sale state |
| stock | Stock VO (full: available + physical) | Expose both fields |
| weight | ?Weight | Variation weight |
| options | list\<ProductVariationOption\> | Size, Color, etc. |
| isComposite | bool | Composite flag |
| defaultSupplier | ?ProductSupplier | Linnworks supplier |
| popularity | ?Popularity | SKU-level rank |
| createdAt | DateTimeImmutable | **NEW — surface from DB** |
| updatedAt | DateTimeImmutable | **NEW — surface from DB** |

### Denormalized parent context (on VariationListItem)

| Field | Type | Notes |
|-------|------|-------|
| parentProductId | IntId | Parent external ID |
| parentSku | ?Sku | Parent product SKU |
| variationTitle | string | Computed: parent title + delimiter + option values. Fallback: just parent title when options empty |
| links | VariationLinks | public_url = parent URL + `?var={sku}` (fallback: parent URL when SKU is null). edit_url = parent edit URL |
| isActive | bool | Parent product visibility |
| vatExclusive | bool | Parent tax treatment |
| vatRelief | bool | Parent VAT relief |
| hasFreeDelivery | bool | Parent delivery flag |
| freeDelivery | ?FreeDeliveryType | Parent delivery type enum |
| mainCategoryIds | list\<IntId\> | Parent main categories (no full categoryIds) |
| resolvedImage | ?ProductImage | From imageIndex + parent images. **Null when imageIndex is null — TBD: fallback to first parent image?** |

### Conditional includes (`?include=`)

| Include | Type | Notes |
|---------|------|-------|
| sale_settings | ?SaleSettings | Parent sale metadata (reason, dates, ends-stock) |
| inventory | ?ProductInventory | Variation Linnworks inventory |
| suppliers | list\<ProductSupplier\> | Variation supplier list |

## Decisions Made

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | Standalone `GET /api/variations` (not per-product sub-resource) | Cross-catalog browsing without navigating to parent first |
| 2 | Self-contained rows with denormalized parent | Frontend table needs no second API call |
| 3 | Composition VO shape (holds ProductVariationView, not flattened) | No field duplication, no divergence risk, single source of truth |
| 4 | SQL view expansion for filterable parent fields | Single query handles filtering; mirrors products_view pattern |
| 5 | mainCategoryIds only (not full categoryIds) | Main categories sufficient for table filtering |
| 6 | saleSettings behind `?include=sale_settings` | Matches products endpoint pattern |
| 7 | Variation title = parent title + delimiter + option values | User-specified format (e.g., "Toilet Sign - Red 300mm Self-Adhesive") |
| 8 | No slug (redundant with links) | Public URL sufficient |
| 9 | No sortOrder (covered by popularity) | SKU-level popularity serves same purpose |
| 10 | No parent popularity (variation-level suffices) | Already on ProductVariationView |
| 11 | No hasAnySale (parent aggregate = noise) | Each variation has its own isOnSale |
| 12 | Fallback: no SKU → public_url = parent URL only | Legacy data degrades gracefully |
| 13 | Fallback: no options → variation title = parent title only | Legacy data degrades gracefully |
| 14 | Stock VO: expose both available + physical at top level | User choice, overriding existing pattern |

## Resolved TBDs

All originally-open questions have been resolved:

| # | Resolution |
|---|------------|
| 1 | resolvedImage = null when imageIndex is null (no fallback to parent image) |
| 2 | Title format: `{Product Title} - {option1} {option2} {optionN}` — single dash, space-separated options |
| 3 | Query params: mirror products endpoint (page, per_page, sort_by, sort_direction, main_category_id, is_on_sale, is_active, has_free_delivery) — but NO sku filter |
| 4 | Include SKU-less variations (they're errors but still shown) |
| 5 | Full Stock VO (available + physical) on new endpoint only. Existing ProductVariationResource stays as-is (availableStock int) |
| 6 | Add createdAt/updatedAt to existing ProductVariationView too (all consumers get timestamps) |

## ~~Open Questions (TBD)~~ — All resolved

| # | Question | Impact |
|---|----------|--------|
| 1 | resolvedImage fallback when imageIndex is null — return null or first parent image? | Resource shape |
| 2 | Variation title delimiter format (` - ` or ` / ` or other) | Display logic |
| 3 | Pagination/sorting/filtering query parameters (sort_by, per_page, category_id filter, etc.) | Controller/DTO design |
| 4 | Whether to filter out SKU-less variations at the SQL level or include them | SQL view definition |
| ~~5~~ | ~~M2 (Stock)~~ — RESOLVED: No. New endpoint exposes full Stock VO (available + physical). Existing ProductVariationResource stays as-is (availableStock int only). | — |
| 6 | Whether `createdAt`/`updatedAt` surfacing should also be done on the existing ProductVariationView (for nested use) or only on the new composition VO | Scope |

## Implementation Phases (Draft)

### Phase 1: Data Layer
- Expand `catalog.product_variations_view` SQL view with parent columns
- Add created_at/updated_at to ProductVariationView (or only to new VO — TBD #6)
- Create `VariationListItem` composition VO

### Phase 2: Assembler + Use Case
- Create `VariationListAssembler` (composes variation view models + parent context)
- Create `ListVariationsUseCase` (pagination, filtering, sorting)
- Create `ListVariationsQuery` DTO

### Phase 3: API Layer
- Create `VariationListResource` (API resource)
- Create `ListVariationsRequestDTO` (request validation)
- Add route `GET /api/variations`
- Wire controller method

### Phase 4: Tests
- Unit tests for VariationListItem VO (title computation, link generation, image resolution)
- Unit tests for VariationListAssembler
- Integration tests for endpoint (filtering, pagination, includes)

## Related Files

- `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php`
- `app/Domain/Catalog/Product/ValueObjects/ProductView.php`
- `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`
- `app/Presentation/Http/Api/Resources/ProductVariationResource.php`
- `app/Presentation/Http/Api/Resources/ProductResource.php`
- `database/migrations/2026_04_18_024602_add_available_physical_stock_to_catalog_product_views.php` (latest view def)
