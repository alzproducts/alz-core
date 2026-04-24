# Fix: `/api/products` returns `profit_margin: 100` when cost price is zero/null

## Context

A production fix was just shipped (PR #631, commit `25111c72`) intending to correct `/api/products/{id}` returning 100% profit margin for products with no cost price. The symptom is still present — for example, product `2818760` (Cafe Mural) now returns `profit_margin: null` on the parent but `profit_margin: 100` on every variation, with `cost_price: null` and `default_supplier.purchase_price: null` alongside it.

Confirmed live via `GET /api/products/2818760` and a direct query against `catalog.product_variations_view`.

## Why PR #631 didn't fix it

PR #631 modified `app/Domain/Catalog/Product/Resolvers/VariationPriceResolver.php` — a domain class that runs on the **write path** (ShopWired sentinel normalisation when saving variation prices). The **read path used by the API never touches that resolver**: it reads from Postgres views that pre-compute every price/margin column in SQL.

## Root cause

The margin expression in both catalog views guards on `s.purchase_price IS NOT NULL` but not on non-zero:

```sql
CASE
    WHEN s.purchase_price IS NOT NULL AND pr.effective_price_net > 0
        THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
    ELSE NULL
END AS profit_margin
```

When `linnworks.stock_item_suppliers.purchase_price = 0.00` (which the current DB has for the FFMURCAFE variations — verified with a direct SELECT), the guard passes and the formula collapses to `(price − 0) / price * 100 = 100`.

The `cost_price` column on the view is also raw `s.purchase_price` (= 0 in this case), but it *appears* null downstream because `ProductVariationView::__construct` wraps it in `Money::nonZeroOrNull(...)`, which silently collapses 0 to null. That PHP-level masking hid the inconsistency from ordinary inspection — the cost reads null, but the margin keeps showing the 100% that was derived from zero.

Both the parent (`catalog.products_view`) and the variation (`catalog.product_variations_view`) views share the same bug. Product 2818760's **parent** happens to read `null` only because the parent SKU `FFMURCAFE` has no matching row in `linnworks.stock_items` — so the outer `LEFT JOIN` yields NULL and the CASE falls through to `ELSE NULL`. A single-listing product (no variations) with a zero-cost supplier would exhibit the bug on the parent view too.

**Null vs zero in Linnworks — separate question, intentionally out of scope.** Whether `purchase_price = 0` should ever be stored (vs. NULL) is an upstream sync question. For this fix we take the user's stance: regardless of what 0 means, margin should not be calculated against it.

## Critical files

- **Live `catalog.products_view` definition**: `database/migrations/2026_04_21_041810_add_popularity_to_catalog_products_view.php`
  - Parent margin CASE: `up()` line **132** (also present verbatim in `down()` line 281 as rollback)
- **Live `catalog.product_variations_view` definition**: `database/migrations/2026_04_18_024602_add_available_physical_stock_to_catalog_product_views.php`
  - Variation margin CASE: `up()` line **243** (also present in `down()` line 474 as rollback)
- **Read-model pass-through (no change needed)**:
  - `app/Infrastructure/Catalog/Product/Models/ProductVariationViewModel.php` — casts `profit_margin` to float
  - `app/Infrastructure/Catalog/Product/Mappers/ProductVariationModelMapper.php:77` — passes view value through
  - `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php:78` — stores value unchanged
  - `app/Presentation/Http/Api/Resources/ProductVariationResource.php:48` — serialises to JSON
- **Write-path resolver (not on read path, unchanged)**: `app/Domain/Catalog/Product/Resolvers/VariationPriceResolver.php`

## Fix

Tighten the guard on the margin CASE in **both** view definitions: change `s.purchase_price IS NOT NULL` to `s.purchase_price > 0`. Zero cost → `profit_margin` is NULL.

```sql
CASE
    WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
        THEN ROUND((pr.effective_price_net - s.purchase_price) / pr.effective_price_net * 100, 2)
    ELSE NULL
END AS profit_margin
```

Keep the `cost_price` column as `s.purchase_price` unchanged — downstream `Money::nonZeroOrNull` already turns 0 into null for callers, so no user-visible behaviour is affected. (A follow-up could push zero-as-null into the view for SQL-layer consistency, but it's not needed to fix the reported bug and the user has explicitly descoped the null-vs-zero question.)

### Implementation

Create one new migration that DROP + CREATEs both views with the tightened guard. Views can't be `ALTER`ed in Postgres (the April 21 migration used `CREATE OR REPLACE` as an exception, but only to *add* columns — removing columns still requires full recreation).

- New file: `database/migrations/2026_04_24_<timestamp>_fix_catalog_views_margin_zero_cost.php`
- `up()`:
  - `DROP VIEW IF EXISTS catalog.products_view` + `CREATE VIEW ...` — copy verbatim from `2026_04_21_041810_add_popularity_to_catalog_products_view.php::up()` (the latest live definition, includes `popularity_rank`/`popularity_max`), changing only the CASE guard.
  - `DROP VIEW IF EXISTS catalog.product_variations_view` + `CREATE VIEW ...` — copy verbatim from `2026_04_18_024602_add_available_physical_stock_to_catalog_product_views.php::up()` (the latest live definition for variations), changing only the CASE guard.
- `down()`:
  - Restore `catalog.products_view` to the exact SQL from `2026_04_21_041810...::up()` (buggy-but-previous form, with popularity columns).
  - Restore `catalog.product_variations_view` to the exact SQL from `2026_04_18_024602...::up()` (buggy-but-previous form).

The two view bodies are long — copy verbatim from the correct source migration and change only the two `CASE` lines (one per view). No JOIN / CTE / column-list changes.

## Verification

1. Run the new migration locally: `php artisan migrate`
2. Re-issue `GET /api/products/2818760` and assert every variation now reports `profit_margin: null` (currently `100`). The parent was already `null` and should stay `null`.
3. Direct SQL check:
   ```sql
   SELECT sku, cost_price, profit_margin
   FROM catalog.product_variations_view
   WHERE product_external_id = 2818760;
   ```
   Expect `profit_margin` = NULL on all 16 rows.
4. Sanity pick a product known to have a real non-zero cost (pick any row from
   `catalog.product_variations_view WHERE profit_margin IS NOT NULL AND profit_margin < 100`) and confirm its margin is unchanged.
5. Run the view-adjacent read tests: `make test-quick` and targeted feature tests for `/api/products` (already covered by `ae5c69f8 fix(catalog): variant-only stock and price regressions on /api/products (#609)` test suite).
6. Deploy: the migration must run in prod to take effect — the fix is SQL-only, no code-deploy alone will change the response.
