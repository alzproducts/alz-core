# Implementation Log: Hourly ShopWired Offers On-Sale Filter Sync

**GitHub Issue**: #518
**Plan Document**: `.ai/plans/2026-04-11_518-hourly-shopwired-offers-on-sale-filter-sync.md`
**Status**: In Progress
**Started**: 2026-04-11
**Completed**: —

## Overview

Hourly sync of the ShopWired "Offers → On Sale" product filter (external_id `10073`, optionNo `14`) so that the storefront filter matches the canonical sale-active rule (`sale_price IS NOT NULL AND sale_price > 0 AND sale_price < price`), including variant-level sales. Mirrors the VAT-relief pattern (#516) with one structural twist: Offers is a multi-value filter slot, so the SQL view must merge-preserve sibling values (e.g. "Free Delivery").

## Decision Log

### 2026-04-11

- **Decision**: Return `jsonb` from the view (not `text[]`) and use `OffersFilterValue::fromJsonArray` that `json_decode`s the column value.
- **Why**: Postgres `text[]` quotes elements containing whitespace (`{"On Sale"}`), so the VAT-relief path's naive `explode(',')` parser would break. Switching to `jsonb` eliminates the quoting/escaping edge case entirely — `[]` and `["On Sale"]` are unambiguous. The view already computes a jsonb array internally (`desired_filter_values_json`); emitting it directly also removes a wasteful `jsonb → text[] → string` round-trip.
- **Tradeoff**: Small shape divergence from the VAT-relief repository pattern (different parser method name, different row column type). Not retrofitting VAT-relief because its only value (`Yes`) never needs quoting and would be scope creep. User approved this deviation in conversation.

- **Decision**: Merge-preserving SQL view — builds the desired `filters->'14'` array by stripping any casing of "on sale" from the current slot and re-appending canonical `"On Sale"` when `is_on_sale`.
- **Why**: `filters->'14'` can hold siblings like `"Free Delivery"`. `UpdateProductFilterJob` replaces the whole slot, so a view emitting just `['On Sale']` / `[]` would destroy siblings.
- **Tradeoff**: More complex SQL than VAT-relief, but mandatory for correctness. Also incidentally normalises the 3 pre-existing legacy lowercase `"On sale"` rows to title case on first run — flagged for the PR body.

- **Decision**: Order-insensitive diff via `jsonb_agg(... ORDER BY value)` on both sides of `IS DISTINCT FROM`.
- **Why**: Avoids false-positive drift rows when the storefront reorders the array (e.g. `["Free Delivery","On Sale"]` vs `["On Sale","Free Delivery"]`).

- **Decision**: Variant-price inheritance via `COALESCE(v.price, p.price)` in the variant EXISTS check.
- **Why**: `shopwired.product_variations.price = NULL` means "inherit parent price"; without the coalesce, a NULL variant price would make the `sale_price < price` comparison return NULL (false) and miss real sales.

- **Decision (simplify phase)**: Restructure the SQL view so `current_sorted` and `desired_filter_values` are both pre-sorted in a single CTE column, then compare directly via `IS DISTINCT FROM`.
- **Why**: The previous version concatenated `|| '["On Sale"]'::jsonb` onto a sorted siblings array — which breaks sort order when "On Sale" isn't alphabetically last (e.g. `["X Prize", "On Sale"]`). The WHERE clause then had to recompute `jsonb_agg(... ORDER BY)` on BOTH sides of the diff, doubling jsonb work per row. Switching to `UNION ALL` of siblings + conditional `'On Sale'` inside a single `jsonb_agg(... ORDER BY value)` produces an already-sorted desired array. Drops from ~4 jsonb_agg per row to 2, and the WHERE becomes `current_sorted IS DISTINCT FROM desired_filter_values`.
- **Tradeoff**: Slightly denser SQL (the `UNION ALL ... SELECT 'On Sale' WHERE pss.is_on_sale` idiom is less common than naive concatenation), but the correctness and efficiency win outweigh it. Verified post-migration: view returns 20 drift rows (live DB).

- **Decision (simplify phase)**: Revert `SyncOffersFiltersUseCaseTest` Mockery expectations from direct `IntId::from(N)` back to `Mockery::on(fn(IntId $id) => $id->value === N)` closures.
- **Why**: The simplify agent flagged the closures as redundant on the assumption that Mockery would match `IntId` instances by value equality. It does not — Mockery's default `with()` matcher treats distinct value-object instances as different arguments even when their public properties are identical. Reverting restored 4/4 passing tests.

## Deviations from Plan

- **`OffersFilterValue::fromJsonArray` + view returns `jsonb`**, not `text[]` + `fromPostgresArray`. User approved during implementation. See decision log above.

## Blockers / Open Questions

- [ ] None

## Technical Notes

- `FilterGroupOptionNo` enum gains `case Offers = 14;`. All three guard tests (CustomerRating, VatRelief, Offers) referenced in class docblock.
- `CatalogServiceProvider` binds `OffersFilterQueryRepositoryInterface` as `scoped` and adds it to `provides()`.
- `CatalogScheduleServiceProvider` adds `registerOffersFilterSchedule()` with the same cadence as VAT-relief: `hourly()->timezone('Europe/London')->onOneServer()->withoutOverlapping(30)`.
- `SyncOffersFiltersJob` is byte-identical to `SyncVatReliefFiltersJob` except for `uniqueId()` and the UseCase type.
- `OffersFilterGroupGuardTest` asserts `external_id = 10073` AND `option_no = 14`. Title is admin-editable — not asserted.

## PR Notes

### What
Hourly background sync that reconciles the ShopWired "Offers → On Sale" product filter (optionNo 14) against the canonical pricing rule, including variant-level sales.

### Why
The on-sale tickbox on the storefront drifts from actual pricing state — a product can have `sale_price < price` without the Offers filter reflecting it, or vice versa. An hourly view-driven sync catches that drift and dispatches per-product filter updates via the shared `UpdateProductFilterJob` infrastructure that landed with #516.

### Key Decisions
- **Merge-preserving view** — `filters->'14'` is a multi-value slot (e.g. "Free Delivery" lives alongside "On Sale"). The view computes the full desired array by preserving siblings and toggling only the "On Sale" entry, so the replace-style `UpdateProductFilterJob` can't clobber them.
- **Order-insensitive diff** — `jsonb_agg(... ORDER BY value)` on both sides of `IS DISTINCT FROM` avoids spurious drift when arrays simply reordered.
- **Variant sale inheritance** — variants with `price = NULL` inherit the parent price; the view uses `COALESCE(v.price, p.price)` in the variant EXISTS check.
- **`jsonb` return + `json_decode` parser** — The view emits `desired_filter_values` as `jsonb` (not `text[]`). `OffersFilterValue::fromJsonArray` decodes it directly. Chosen over `text[]` because Postgres quotes text-array elements containing whitespace, which would have required a hand-rolled escape-aware parser for no benefit. Divergence from the VAT-relief `fromPostgresArray` pattern is intentional — VAT-relief's `'Yes'` value never needs quoting so its simpler parser remains valid for that enum.
- **Incidental legacy normalisation** — the live DB has 3 rows using lowercase `"On sale"`. The view strips any casing of "on sale" before re-appending canonical `"On Sale"`, so the first run will emit 3 extra writes that fix the legacy casing.

### Testing
- Unit: `SyncOffersFiltersUseCaseTest` (4 scenarios: empty, dispatches, null-for-removal, mixed).
- Guard: `OffersFilterGroupGuardTest` asserts `external_id = 10073`, `option_no = 14`.
- Full `make test` + `make lint`.
