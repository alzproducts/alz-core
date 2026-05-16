# COR-135: Add net_margin_single_unit to catalog product views

## Context

`profit_margin` shows gross margin without considering absorbed shipping costs. For free-delivery products, the business pays for shipping out of its margin on every single-unit order — the worst case. `net_margin_single_unit` makes this explicit: it adds the absorbed shipping cost to the product's cost base before computing margin %, so the column shows the floor margin when exactly one item ships.

For non-free-delivery products the two values are identical (no shipping is absorbed). A new static config table `catalog.free_delivery_shipping_costs` stores the VAT-exclusive cost per tier (`Standard` = £3.50, `Express` = £4.50). The view LEFT JOINs this table; a non-matching row (`COALESCE(fdsc.cost, 0)`) naturally falls back to the standard profit_margin formula.

## Files to create / modify

| Action | Path |
|--------|------|
| **Create** | `database/migrations/2026_05_16_100000_create_catalog_free_delivery_shipping_costs_table.php` |
| **Create** | `database/migrations/2026_05_16_100001_add_net_margin_single_unit_to_catalog_product_views.php` |
| **Modify** | `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php` |
| **Modify** | `app/Infrastructure/Catalog/Product/Models/ProductVariationViewModel.php` |

---

## Step 1 — Create `catalog.free_delivery_shipping_costs` table migration

**File:** `database/migrations/2026_05_16_100000_create_catalog_free_delivery_shipping_costs_table.php`

```php
up():
    DB::statement("
        CREATE TABLE catalog.free_delivery_shipping_costs (
            delivery_type VARCHAR NOT NULL PRIMARY KEY,
            cost          NUMERIC(8,2) NOT NULL
        )
    ");

    DB::statement("
        INSERT INTO catalog.free_delivery_shipping_costs (delivery_type, cost) VALUES
            ('Standard', 3.50),
            ('Express',  4.50)
    ");

down():
    DB::statement('DROP TABLE IF EXISTS catalog.free_delivery_shipping_costs');
```

Key decisions: `delivery_type` is VARCHAR PK matching `FreeDeliveryType` enum casing exactly (`'Standard'`, `'Express'`). No 'none' row. Two rows — update in place when carrier prices change.

---

## Step 2 — View migration: add `net_margin_single_unit`

**File:** `database/migrations/2026_05_16_100001_add_net_margin_single_unit_to_catalog_product_views.php`

Both views get:
1. A new LEFT JOIN added to the FROM clause
2. A new `net_margin_single_unit` column at the end of the SELECT

### New JOIN (same alias `fdsc` for both views)

Place near the other `catalog.*` LEFT JOINs at the bottom of the FROM clause.

```sql
-- Free delivery shipping cost absorbed per single-unit order
-- (matches 'Standard'/'Express'; non-free-delivery rows have no match → NULL → COALESCE to 0)
LEFT JOIN catalog.free_delivery_shipping_costs fdsc
    ON p.custom_fields->>'free_delivery' = fdsc.delivery_type
```

- **products_view**: `p` is the existing `shopwired.products p` alias in the FROM
- **variations_view**: `p` is the existing `INNER JOIN shopwired.products p ON p.id = v.product_id` alias

### New column (append after `popularity_max` / `stock_value`)

```sql
-- Computed: worst-case gross margin % when a single unit absorbs full shipping cost.
-- COALESCE(fdsc.cost, 0) = 0 for non-free-delivery products (no JOIN match),
-- making this identical to profit_margin for those products.
-- NULL when cost_price is missing/zero — consistent with profit_margin.
CASE
    WHEN s.purchase_price > 0 AND pr.effective_price_net > 0
        THEN ROUND(
            (pr.effective_price_net - (s.purchase_price + COALESCE(fdsc.cost, 0::numeric)))
            / pr.effective_price_net * 100,
            2
        )
    ELSE NULL
END AS net_margin_single_unit
```

### down() bodies (verbatim copies from prior migrations)

- **products_view down** → copy from `2026_04_24_120000_fix_catalog_views_margin_zero_cost::up()` (no net_margin_single_unit, no fdsc JOIN)
- **variations_view down** → copy from `2026_05_06_100001_add_supplier_name_and_stock_value_to_catalog_product_variations_view::up()` (no net_margin_single_unit, no fdsc JOIN)

Migration structure follows the DROP + CREATE pattern used by every prior view migration.

---

## Step 3 — Update `ProductViewModel`

**File:** `app/Infrastructure/Catalog/Product/Models/ProductViewModel.php`

Two changes:

1. Add to the docblock (after `@property int|null $popularity_max`):
   ```php
   * @property float|null $net_margin_single_unit Worst-case gross margin % for a single-unit free-delivery order
   ```

2. Add to `numericCasts()` (after `'popularity_max' => 'integer'`):
   ```php
   'net_margin_single_unit' => 'float',
   ```

---

## Step 4 — Update `ProductVariationViewModel`

**File:** `app/Infrastructure/Catalog/Product/Models/ProductVariationViewModel.php`

Two changes:

1. Add to the docblock (after `@property float|null $stock_value`):
   ```php
   * @property float|null $net_margin_single_unit Worst-case gross margin % for a single-unit free-delivery order
   ```

2. Add to `casts()` (after `'stock_value' => 'float'`):
   ```php
   'net_margin_single_unit' => 'float',
   ```

---

## Behaviour summary

| has_free_delivery | cost_price | net_margin_single_unit |
|---|---|---|
| true (Standard/Express) | > 0 | margin % minus shipping absorption |
| true | NULL or 0 | NULL |
| false | > 0 | = profit_margin (COALESCE adds 0) |
| false | NULL or 0 | NULL |

Example: £20 inc VAT product, £8 cost (ex VAT), Standard free delivery (£3.50 ex VAT):
- `effective_price_net = 20 / 1.20 = 16.67`
- `net_margin_single_unit = (16.67 - 11.50) / 16.67 * 100 = 31.01%`
- `profit_margin = (16.67 - 8.00) / 16.67 * 100 = 52.01%`

---

## Known limitations

- **Exact-string JOIN on `delivery_type`**: `has_free_delivery` is computed via negative checks (`!= 'none'`, `!= ''`, `IS NOT NULL`), so any non-empty string passes. The new JOIN uses exact PK equality on `'Standard'`/`'Express'`. If the custom field contains a malformed value (typo, wrong casing, unexpected tier name), `has_free_delivery = true` but `net_margin_single_unit = profit_margin` silently (no shipping deducted). Accepted risk — `FreeDeliveryType` enum enforces values on the write path, and divergent ShopWired data is rare.

## Verification

```sql
-- Should be < profit_margin for all rows
SELECT sku, profit_margin, net_margin_single_unit
FROM catalog.products_view
WHERE has_free_delivery = true AND cost_price IS NOT NULL
LIMIT 10;

-- Should equal profit_margin for all rows
SELECT sku, profit_margin, net_margin_single_unit
FROM catalog.products_view
WHERE has_free_delivery = false AND cost_price IS NOT NULL
LIMIT 10;

-- Should be NULL
SELECT COUNT(*) FROM catalog.products_view
WHERE cost_price IS NULL AND net_margin_single_unit IS NOT NULL; -- expect 0
```

Then: `make lint` and `make test`
