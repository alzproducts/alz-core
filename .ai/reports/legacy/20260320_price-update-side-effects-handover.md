# Price Update Side-Effects Handover

All downstream side-effects triggered when `sellingPrice` or `salePrice` are updated. This excludes the initial ShopWired price update itself (already documented) and infrastructure concerns (logging, caching).

## Important: Two Entry Points, Different Side-Effects

There are two distinct entry points for selling price updates, and they trigger **different** side-effect chains:

| Entry Point | Context | Side-Effects |
|-------------|---------|-------------|
| `UpdatePricesFromFormService` | Bulk cost/price editing form | Full event chain: ShopWired price + Linnworks EPs + Slack + profit sync |
| `Prices.php` (non-sale path) | Individual product price form | Direct ShopWired update + profit sync **only** (no Linnworks EPs, no Slack) |

`Prices.php` does **not** dispatch `ProductSellingPriceUpdatedEvent`. It updates ShopWired directly via `Shop_UpdateProductbyId()`. This means Linnworks `SellingPriceGross`/`SellingPriceNet` and the Slack notification are **only** triggered from `UpdatePricesFromFormService`.

---

## 1. Selling Price Updated (via UpdatePricesFromFormService only)

**Trigger:** `ProductSellingPriceUpdatedEvent` dispatched from `UpdatePricesFromFormService.php:267-270`

### Side-Effect A: Update ShopWired Product Price

The listener finds the product in ShopWired by SKU, then determines if it's a master or variant product.

**Master product:**
- **API:** `PUT /products/{productId}`
- **Payload:** `{"price": {sellingPrice}}`
- **Cache:** Deletes ShopWired product cache entry after update

**Variant product:**
- **API:** `PUT /products/{masterProductId}/variations/{variationId}`
- **Payload:** `{"price": {sellingPrice}}`
- **Cache:** Deletes ShopWired product cache entry for the master ID after update

**Implementation:** `ProductSellingPriceUpdatedListener.php` → `UpdateEntity::updateEntity()` → `RestClient::put()`

### Side-Effect B: Update Linnworks Extended Properties

Updates two EPs on the Linnworks stock item via `LinnworksSellingPriceUpdateService`:

| EP Name | Value |
|---------|-------|
| `SellingPriceGross` | The new gross selling price |
| `SellingPriceNet` | Calculated from gross using the product's tax rate via `FloatUtils::grossToNet()` |

**Implementation:** `UpdateStockItemEpService::update()` → `AlzInvUpdate::oneEp()` → Linnworks `UpdateInventoryItemExtendedProperties` API. Creates the EP if it doesn't exist, updates if value changed.

### Side-Effect C: Slack Notification

Message to `general` channel: `*Selling Price Update:* {title} updated to *{formattedPrice}*`

### Side-Effect D: Profit Category Synchronization

Called from `UpdatePricesFromFormService.php:284` as a separate closure that runs unconditionally for **all** form submissions (cost, RRP, selling price, URL) — not exclusively tied to selling price changes. Also called from `Prices.php:185` but **only** for regular (non-sale) price updates — sale add/remove operations return early before this runs.

See [Section 4: Profit Category Sync](#4-profit-category-synchronization) for full details.

---

## 2. Product Added to Sale

**Trigger:** `ProductAddedToSaleEvent` dispatched from `Prices.php:145-155`

**Validation:** Sale price must be > 0 AND less than the product's regular price. Throws `InvalidArgumentException` if not.

**Note:** Adding a product to sale does **not** trigger profit category re-sync — `Prices.php` returns early at line 170-172 before reaching the profit sync code.

### Side-Effect A: Add Product to Sale Category in ShopWired

1. Fetches the full product from ShopWired: `GET /products/{id}`
2. Reads existing `categories` array from the product
3. Appends category ID `64939` (Sale master category, defined in `CategoryConstants::SALE_MASTER_ID`)
4. Updates the product:
   - **API:** `PUT /products/{id}`
   - **Payload:** `{"categories": [{existing category IDs}, 64939]}`

**Implementation:** `ShopWiredA::addProductToSaleCategory()` → `categoryRemoveAdd()` → `productUpdateRawData()`

### Side-Effect B: Update Sale Price on ShopWired

- **API:** `PUT /products/{id}`
- **Payload:** `{"salePrice": {salePrice}}`

**Implementation:** `ShopWiredA::UpdateProduct()`

### Side-Effect C: Update Sort Order (Conditional)

Only updates if the product's current `sortOrder` is `null` or greater than `3`:
- **API:** `PUT /products/{id}`
- **Payload:** `{"sortOrder": 3}`

The original sort order is preserved in the `default_sort_order` custom field (see below) for restoration when removed from sale.

If the existing sort order is already ≤ 3, no update is made.

### Side-Effect D: Update ShopWired Custom Fields

Merges the following fields into the product's existing custom fields, then sends a single update:

| Custom Field Key | Value | Notes |
|-----------------|-------|-------|
| `sale_ends_stock` | Quantity threshold (string) | When stock reaches this level, sale auto-removes |
| `sale_date_end` | Unix timestamp | Converted from DateTime; auto-removal trigger |
| `sale_date_start` | Unix timestamp | Current datetime at time of adding |
| `sale_reason` | String | Business reason for the sale |
| `sale_comments` | String | Additional notes |
| `default_sort_order` | String (int) | Product's sort order before sale (for restoration) |

**API:** `PUT /products/{id}?embed=custom_fields`
**Payload:** `{"customFields": {merged custom fields object}}`

**Implementation:** `UpdateSwCf::update()` which:
1. Fetches live custom fields from ShopWired: `GET /products/{id}` (field: `customFields`)
2. Merges new values with existing (preserving unrelated custom fields)
3. Converts DateTime values to Unix timestamps
4. Sends the merged result back

### Side-Effect E: Update Linnworks Extended Property

| EP Name | Value |
|---------|-------|
| `is_in_sale` | `'1'` |

**Implementation:** `UpdateStockItemEpService::update()` → `AlzInvUpdate::oneEp()`

### Side-Effect F: Slack Notification

Formatted message to `general` channel containing:
- Product title with URL link
- Original price (struck through) and sale price
- Discount percentage
- Sale reason, comments, end date, end stock level (if provided)

---

## 3. Product Removed from Sale

**Trigger:** `ProductRemovedFromSaleEvent` dispatched from:
- **Manual:** `Prices.php:158-165` with reason `REASON_MANUAL` (includes StockItem)
- **Automatic:** `ProductSaleAutomaticRemovalService` (does NOT include StockItem — see [Section 5](#5-automatic-sale-removal-cron))

**Note:** Removing a product from sale does **not** trigger profit category re-sync — `Prices.php` returns early at line 170-172 before reaching the profit sync code.

### Side-Effect A: Remove Product from Sale Category in ShopWired

1. Fetches the full product from ShopWired: `GET /products/{id}`
2. Reads existing `categories` array
3. Removes category ID `64939` from the array
4. Updates the product:
   - **API:** `PUT /products/{id}`
   - **Payload:** `{"categories": [{remaining category IDs without 64939}]}`

**Implementation:** `ShopWiredA::removeProductFromSaleCategory()` → `categoryRemoveAdd()`

### Side-Effect B: Clear Sale Price on ShopWired

- **API:** `PUT /products/{id}`
- **Payload:** `{"salePrice": ""}`

Setting to empty string clears the sale price field.

**Implementation:** `ShopWiredA::productUpdateMainKeysChangeToNull()` → `UpdateProduct()`

### Side-Effect C: Restore Sort Order

Reads the `default_sort_order` custom field from the product (saved when added to sale). If a value exists, restores it:

- **API:** `PUT /products/{id}`
- **Payload:** `{"sortOrder": {previousSortOrder}}` or `{"sortOrder": ""}` if no previous value

### Side-Effect D: Clear Sale Custom Fields

Clears all sale-related custom fields by setting them to empty strings:

| Custom Field Key | Value |
|-----------------|-------|
| `sale_ends_stock` | `""` |
| `sale_date_end` | `""` |
| `sale_date_start` | `""` |
| `sale_reason` | `""` |
| `sale_comments` | `""` |
| `default_sort_order` | `""` |

**API:** `PUT /products/{id}?embed=custom_fields`
**Payload:** `{"customFields": {merged custom fields with empty sale values}}`

Same merge process as adding — fetches live fields first, merges empty values, sends back.

### Side-Effect E: Update Linnworks Extended Properties

**Only if the event includes a `StockItem`** (manual removals from `Prices.php` include one; automatic removals do NOT):

| EP Name | Value |
|---------|-------|
| `last_sale_end_date` | Current datetime (`Y-m-d H:i:s`) |
| `is_in_sale` | `'0'` |

**Consequence of automatic removals:** When `ProductSaleAutomaticRemovalService` triggers removal, no `StockItem` is passed. This means Linnworks `is_in_sale` **remains `'1'`** and `last_sale_end_date` is never set. Any downstream system relying on these EPs will still think the product is on sale.

**Implementation:** `UpdateStockItemEpService::update()` → `AlzInvUpdate::oneEp()`

### Side-Effect F: Slack Notification

Formatted message to `general` channel containing:
- Product title with URL link
- Sale price (struck through) and original price
- Previous discount percentage
- Removal reason (human-readable)
- Original sale reason and comments (from custom fields)

---

## 4. Profit Category Synchronization

**Triggered from:**
- `UpdatePricesFromFormService.php:284` — runs unconditionally for all form submissions, not just selling price changes
- `Prices.php:185` — runs only for regular (non-sale) price updates; sale add/remove operations skip this

**Implementation:** `ProductProfitCategoryManager::synchronizeProductProfitCategory()`

This recalculates the product's profit margin category based on current prices and costs, and updates both ShopWired and Linnworks **only if the category has changed**.

### Process:

1. Gets the first SKU from the product (master SKU or first variation SKU)
2. Fetches margin data via `GetOneProductMargin::getMargins()` (calculates cost vs. net selling price)
3. Calculates selling margin percentage: `MoneyUtils::calcMarginPercent(netSellingPrice, costPrice)`
4. Determines margin category based on thresholds:

| Margin % | Category Value |
|----------|----------------|
| ≤ 20% | `1 - Low margin` |
| 21–39% | `2 - Standard margin` |
| ≥ 40% | `3 - High margin` |

5. Compares against existing `custom_label_1` custom field on the ShopWired product
6. **If unchanged**, no updates are made
7. **If changed**, updates both systems:

### ShopWired Update:

- **API:** `PUT /products/{id}?embed=custom_fields`
- **Payload:** `{"customFields": {merged fields with custom_label_1 = "{MarginCategory}"}}`
- Custom field key: `custom_label_1`
- Value: One of the margin category strings from the table above (e.g., `"1 - Low margin"`)
- Refreshes ShopWired product cache after successful update

### Linnworks Update:

Looks up the `pkStockItemId` via `AlzInventory::getStockItemIdBySku()`, then updates two EPs:

| EP Name | Value |
|---------|-------|
| `custom_label_1` | Margin category string (e.g., `"1 - Low margin"`) |
| `ProfitMarginPercent` | Integer margin percentage |

---

## 5. Automatic Sale Removal (Cron)

**Implementation:** `ProductSaleAutomaticRemovalService::actionSaleChecks()`

Fetches all ShopWired products with `salePrice > 0` (fields: `id, sku, customFields, salePrice, active, stock`) and checks four removal conditions:

| Condition | Removal Reason | Filter Logic |
|-----------|---------------|--------------|
| Product is inactive | `product_inactive` | `active === false` |
| Sale end date in the past | `end_date_reached` | `active === true` AND `sale_date_end` custom field is non-empty AND `<= strtotime('today')` |
| Out of stock AND discontinued | `out_of_stock_and_discontinued` | `active === true` AND `stock === 0` AND `discontinued` custom field is non-empty |
| All sale units sold | `out_of_stock_and_discontinued`* | `sale_ends_stock` custom field is non-empty AND `stock <= sale_ends_stock` |

**\*Bug:** `removeProductsWithSaleUnitsSold()` at line 187 dispatches with `REASON_NO_STOCK_AND_DISC` (`out_of_stock_and_discontinued`) instead of `REASON_SALE_UNITS_SOLD` (`sale_units_sold`). This means the Slack notification and any reason-based logic will show the wrong removal reason for products removed due to sale quantity thresholds.

For each matching product, dispatches `ProductRemovedFromSaleEvent` with the appropriate reason. This triggers all the side-effects described in [Section 3](#3-product-removed-from-sale).

**Important:** The automatic removal does NOT pass a `StockItem` to the event, so Linnworks EP updates (`is_in_sale`, `last_sale_end_date`) are **skipped** for all automatic removals. After automatic removal, Linnworks will still report the product as being in sale.

---

## Summary: All ShopWired API Calls

All calls go through `ShopWiredA` which uses the ShopWired REST API.

| Side-Effect | HTTP | Endpoint | Payload |
|-------------|------|----------|---------|
| Update selling price (master) | PUT | `/products/{id}` | `{"price": X}` |
| Update selling price (variant) | PUT | `/products/{masterId}/variations/{varId}` | `{"price": X}` |
| Set sale price | PUT | `/products/{id}` | `{"salePrice": X}` |
| Clear sale price | PUT | `/products/{id}` | `{"salePrice": ""}` |
| Update sort order | PUT | `/products/{id}` | `{"sortOrder": X}` |
| Add sale category | PUT | `/products/{id}` | `{"categories": [..., 64939]}` |
| Remove sale category | PUT | `/products/{id}` | `{"categories": [... without 64939]}` |
| Update custom fields | PUT | `/products/{id}?embed=custom_fields` | `{"customFields": {merged object}}` |

## Summary: All Linnworks EP Updates

All go through `UpdateStockItemEpService::update()` → `AlzInvUpdate::oneEp()` → Linnworks `UpdateInventoryItemExtendedProperties` API.

| Context | EP Name | Value | Entry Point |
|---------|---------|-------|-------------|
| Selling price update | `SellingPriceGross` | New gross price | `UpdatePricesFromFormService` only |
| Selling price update | `SellingPriceNet` | Calculated net price | `UpdatePricesFromFormService` only |
| Added to sale | `is_in_sale` | `'1'` | `Prices.php` |
| Removed from sale (manual only) | `is_in_sale` | `'0'` | `Prices.php` |
| Removed from sale (manual only) | `last_sale_end_date` | `'Y-m-d H:i:s'` | `Prices.php` |
| Profit category sync | `custom_label_1` | Margin category string | Both |
| Profit category sync | `ProfitMarginPercent` | Integer percentage | Both |

## Summary: Slack Notifications

All sent to `general` channel via `SlackService::say()` (BotMan).

| Event | Message Format | Entry Point |
|-------|---------------|-------------|
| Selling price updated | `*Selling Price Update:* {title} updated to *{price}*` | `UpdatePricesFromFormService` only |
| Added to sale | `*SALE ADDITION*` with product link, prices, discount %, reason, dates | `Prices.php` |
| Removed from sale | `*SALE REMOVAL*` with product link, prices, previous discount %, removal reason | `Prices.php` + auto cron |
