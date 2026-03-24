# Plan: Order Product Extra Data Table + SKU Override Fix

## Context

**Sentry ALZ-CORE-3Z**: `MixpanelProductPurchasedDTO` asserts `notEmpty($sku)` and crashes when orders with empty SKUs reach the Mixpanel sync pipeline. Orders sometimes arrive from ShopWired without SKUs (2 found in 2 months of data). This data is rich and important — we need to capture it and allow manual correction.

**Goal**: Create a `shopwired.order_product_extra_data` table for manual data quality overrides (starting with `sku_override`), resolve overrides transparently at the repository/mapper level, and add a defensive skip guard for the Mixpanel crash.

**Key discovery**: ShopWired's `external_id` on order products is a **parent product ID**, not a line item ID. Multiple variations of the same product share it (e.g., 6 Door Number variants all share one `external_id`). A `variation_hash` column is needed to create a stable identity for each product-variant combination. The `(order_external_id, external_id)` composite is NOT unique on `order_products` (customers can add identical products twice), but IS functionally unique for the override use case.

---

## Step 1: Add `variation_hash` to `order_products`

**Migration**: `database/migrations/{ts}_add_variation_hash_to_shopwired_order_products.php`

- Add `variation_hash` column: `VARCHAR(32)`, nullable (null = no variation)
- Add index on `(order_external_id, external_id, variation_hash)` for JOIN performance
- **NO unique constraint** — customers can add identical products to basket (duplicate rows are valid)
- Backfill existing rows via PHP — see Step 5

**Hash computation**: `OrderProductModel::computeVariationHash(array $variation): ?string`
- Sort variation array by `name` key (deterministic ordering)
- `json_encode()` with `JSON_THROW_ON_ERROR`
- `md5()` the result
- Empty array or null → return `null`

**Files to modify**:
- `app/Infrastructure/Shopwired/Models/OrderProductModel.php`:
  - Add `variation_hash` to `@property` docblock and casts
  - Add `public static function computeVariationHash(array $variation): ?string` — single source of truth
  - Call `computeVariationHash()` inside `fromDomainAttributes()` to include hash in sync insert rows

---

## Step 2: Create `order_product_extra_data` table

**Migration**: `database/migrations/{ts}_create_shopwired_order_product_extra_data_table.php`

```
shopwired.order_product_extra_data
├── id (UUID PK, gen_random_uuid())
├── order_id (UUID FK → shopwired.orders, CASCADE DELETE)
├── order_external_id (INT) — ShopWired order ID
├── external_id (INT) — ShopWired product ID
├── variation_hash (VARCHAR(32), NULLABLE) — matches order_products.variation_hash
├── sku_override (VARCHAR(100), NULLABLE) — manual SKU correction
├── created_at (TIMESTAMPTZ)
├── updated_at (TIMESTAMPTZ)
├── UNIQUE INDEX on (order_external_id, external_id, COALESCE(variation_hash, ''))
│   └── Requires DB::statement() raw SQL — Laravel's $table->unique() doesn't support expressions
├── INDEX on order_id (FK performance)
```

One override per product-variant-per-order. If a customer adds the same empty-SKU product twice, the single override applies to both line items.

**New file**: `app/Infrastructure/Shopwired/Models/OrderProductExtraDataModel.php`
- Simple Eloquent model, `HasUuids`, `$table = 'shopwired.order_product_extra_data'`
- `BelongsTo` relationship to `OrderModel`
- Does NOT implement `EloquentDomainMappableInterface` (Infrastructure-only metadata)

---

## Step 3: Create `order_products_resolved` database view + wire reads through it

**Key principle**: The override is resolved **at the SQL level** via a database view. `OrderProductModel->sku` already contains the effective SKU when loaded. No mapper changes, no `skuOverride` property, no PHP-level resolution. Consumers never know the difference. The raw SKU is preserved in the `order_products.sku` table column.

**Migration**: `database/migrations/{ts}_create_shopwired_order_products_resolved_view.php`

```sql
CREATE VIEW shopwired.order_products_resolved AS
SELECT
    op.id, op.order_id, op.order_external_id, op.external_id,
    op.title,
    COALESCE(ed.sku_override, op.sku) AS sku,
    op.price, op.price_vat, op.total, op.total_vat,
    op.original_price, op.cost_price,
    op.quantity, op.vat_rate, op.comments,
    op.variation, op.custom_fields,
    op.is_preorder, op.preorder_date,
    op.variation_hash,
    op.created_at, op.updated_at
FROM shopwired.order_products op
LEFT JOIN shopwired.order_product_extra_data ed
    ON ed.order_external_id = op.order_external_id
    AND ed.external_id = op.external_id
    AND COALESCE(ed.variation_hash, '') = COALESCE(op.variation_hash, '')
```

**File**: `app/Infrastructure/Shopwired/Models/OrderModel.php`
- Update `products()` relationship to read from the view:
  ```php
  return $this->hasMany(OrderProductModel::class, 'order_id')
      ->from('shopwired.order_products_resolved');
  ```
- Writes (syncProducts) use `OrderProductModel::class` directly → still hits the table

**No changes needed to**: OrderProductModel, OrderModelMapper, EloquentOrderRepository eager loading

---

## Step 4: Add skip guard + Sentry alert for empty SKUs

Even with the override table, products may have empty SKUs before an operator enters the override. The Mixpanel sync must not crash.

**File**: `app/Infrastructure/Mixpanel/MixpanelClient.php`
- In `buildOrderEvents()` (line 282-290), add guard before creating `MixpanelProductPurchasedDTO`:
  ```php
  if ($product->sku === '') {
      Log::warning('Skipping product with empty SKU in Mixpanel sync', [
          'order_id' => $order->id,
          'product_id' => $product->id,
          'product_title' => $product->title,
      ]);
      // TODO: Also trigger Sentry alert or Slack notification to prompt override entry
      continue;
  }
  ```
- Also filter empty-SKU products from `MixpanelCheckoutCompletedDTO::buildCart()` for consistency

**For the Sentry/Slack alert**: Use `report(new MissingSkuException(...))` or dispatch a Slack notification. Check which pattern the codebase uses for proactive alerting. If `MissingSkuException` doesn't exist, consider reusing `MissingRequiredDataException` from `app/Domain/Exceptions/Data/`.

---

## Step 5: Backfill `variation_hash` for existing data

**Must use PHP, not raw SQL.** PostgreSQL's `md5(variation::text)` produces different output than PHP's `md5(json_encode(...))` due to JSONB text formatting differences.

**Approach**: The Step 1 migration adds the column as nullable. A post-migration PHP backfill iterates existing `order_products` rows, computes the hash using `OrderProductModel::computeVariationHash()`, and updates each row.

Options:
- Inline in the migration's `up()` method using Eloquent chunk/update
- Separate artisan command

For ~1,500 rows (local), this is instant. For production (more data), batch in chunks of 1,000.

---

## Data Entry

Operators enter overrides manually via **Supabase Studio**. No artisan command or admin UI needed for now.

To add an override:
1. Find the order product in `shopwired.order_products` by `order_external_id`
2. Note the `external_id`, `variation_hash` (null for simple products), and `order_id` (UUID)
3. INSERT into `shopwired.order_product_extra_data` with matching values + the `sku_override`

---

## Verification

1. **`make lint`** — all linters pass (PHPStan, Pint, Arkitect, Deptrac)
2. **`make test`** — existing tests pass (no domain VO changes, Mixpanel skip guard is additive)
3. **New unit tests**:
   - `OrderProductModel::computeVariationHash()` — deterministic, null for empty/no variation, stable across calls
   - `MixpanelClient::buildOrderEvents()` — skip guard works for empty-SKU products, alert fires
4. **New integration test**:
   - Insert an order product with empty SKU + matching extra_data override
   - Load the order via `EloquentOrderRepository` → verify `OrderProduct->sku` contains the overridden value (resolved by view)
   - Load an order without extra_data → verify raw SKU preserved
5. **Manual verification**:
   - Run `php artisan migrate`
   - Backfill orders: `php artisan shopwired:backfill-orders --months=2`
   - Insert a test override via tinker for one of the empty-SKU products (order 11494931 or 11461775)
   - Load the order through the repository and verify the SKU is resolved
   - Verify the view works: `SELECT sku FROM shopwired.order_products_resolved WHERE order_external_id = 11494931`

---

## Critical Files Summary

| File | Change |
|------|--------|
| `database/migrations/new_1` | Add `variation_hash` to order_products |
| `database/migrations/new_2` | Create `order_product_extra_data` table |
| `database/migrations/new_3` | Create `order_products_resolved` view |
| `app/Infrastructure/Shopwired/Models/OrderProductModel.php` | Add `computeVariationHash()`, `fromDomainAttributes()` |
| `app/Infrastructure/Shopwired/Models/OrderProductExtraDataModel.php` | **New** — Eloquent model |
| `app/Infrastructure/Shopwired/Models/OrderModel.php` | Update `products()` relationship to read from view |
| `app/Infrastructure/Mixpanel/MixpanelClient.php` | Add skip guard + alert for empty SKUs |
| `app/Infrastructure/Mixpanel/DTOs/MixpanelCheckoutCompletedDTO.php` | Filter empty-SKU products from cart |
