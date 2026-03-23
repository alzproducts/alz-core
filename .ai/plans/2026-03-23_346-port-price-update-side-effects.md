# Port: Price Update Side-Effects

**Source report:** `.ai/reports/legacy/20260320_price-update-side-effects-handover.md`
**EP reference:** `.ai/reports/legacy/20260323_stock-item-ep-update-handover.md`
**Date:** 2026-03-23
**Scope:** Port all downstream side-effects triggered by selling price and sale price changes — ShopWired state management, Linnworks EP updates, and Slack notification enrichment. Excludes profit category synchronisation (deferred).

## Business Requirements

1. When a selling price is updated, sync `SellingPriceGross` and `SellingPriceNet` extended properties to Linnworks
2. When a product is added to sale (salePrice set), update ShopWired: add to sale category, set sort order, write sale metadata custom fields
3. When a product is added to sale, set `is_in_sale` EP to `'1'` on Linnworks
4. When a product is removed from sale (salePrice cleared), update ShopWired: remove from sale category, restore sort order, clear sale metadata custom fields
5. When a product is removed from sale, set `is_in_sale` EP to `'0'` and `last_sale_end_date` on Linnworks (fix legacy bug: auto-removals must also update these EPs)
6. Enrich existing Slack pricing notification with sale context (reason, discount %, end date, removal reason)
7. Automatically remove products from sale when conditions are met: product inactive, sale end date passed, out of stock + discontinued, sale quantity threshold reached (fix legacy bug: correct removal reason for "sale units sold" condition)

## Current Infrastructure

### Available
- `PriceUpdateClient` — batch price + salePrice updates via `POST /products/prices` (`Infrastructure/Shopwired/Clients/PriceUpdateClient.php`)
- `UpdatePriceCommand` — supports salePrice with null/zero semantics (`Domain/Catalog/Product/Commands/UpdatePriceCommand.php`)
- `ProductUpdateClient::updateCustomFields()` — fetch-merge-PUT for custom fields (`Infrastructure/Shopwired/Clients/ProductUpdateClient.php`)
- `ProductFieldUpdateClient` + `ProductFieldUpdate::categories()` — update product categories (`Infrastructure/Shopwired/Clients/ProductFieldUpdateClient.php`)
- `ProductClient::getProductById()` — fetch full product to read current category list (`Infrastructure/Shopwired/Clients/ProductClient.php`)
- `InventoryUpdateClient::addExtendedProperty()` — create EP on stock item (`Infrastructure/Linnworks/Clients/InventoryUpdateClient.php`)
- `ExtendedPropertyName` enum — centralized EP name registry (`Domain/Inventory/Enums/ExtendedPropertyName.php`)
- `UpdateProductPricesUseCase` — single entry point for all price changes (`Application/Shopwired/PricingUpdate/UseCases/UpdateProductPricesUseCase.php`)
- `SkuRetailPricingUpdatedEvent` + `ProductPricingUpdatedEvent` — existing pricing events
- `SkuPriceChange` — already has `addedToSale()`, `removedFromSale()`, `saleChanged()` methods
- `ProductPricingUpdatedSlackListener` + `ProductPricingUpdatedNotification` — existing Slack notification with `[SALE]`/`[SALE ENDED]` labels
- `ChatNotificationInterface` + `SlackChatNotificationClient` — full Slack pipeline
- `HandleApiExceptions` middleware, `ServiceCircuitBreaker`, `ServiceRateLimiter` — job protection
- `ShopwiredScheduleServiceProvider` — schedule registration point
- `ProductRetailPricing` — carries `taxType()` for gross→net calculation

### Needs Extending
- `InventoryUpdateClient` / `InventoryUpdateClientInterface` — replace `addExtendedProperty()` with `setExtendedProperty()` implementing read→compare→create-or-update pattern (per EP handover report)
- `ExtendedPropertyName` enum — add cases: `SellingPriceGross`, `SellingPriceNet`, `IsInSale`, `LastSaleEndDate`
- `ProductUpdatableField` enum — add `SortOrder` case
- `ProductFieldUpdate` VO — add `sortOrder(int)` static factory
- `ProductPricingUpdatedEvent` — carry optional `SaleSettings` context
- `ProductPricingUpdatedNotification` — enrich with sale context (reason, discount %, dates, removal reason)
- `EventServiceProvider` — register new listeners

### Needs Building
- `SaleSettings` domain VO — nullable sale metadata (reason, comments, end date, stock threshold, removal reason)
- `SaleRemovalReason` domain enum — manual, product_inactive, end_date_reached, out_of_stock_discontinued, sale_units_sold
- `ProductAddedToSaleEvent` / `ProductRemovedFromSaleEvent` — domain events dispatched by sale state detection listener
- `DetectSaleStateChangeListener` — listens to `ProductPricingUpdatedEvent`, checks `addedToSale()`/`removedFromSale()`, dispatches sale events with `SaleSettings`
- `UpdateLinnworksSellingPriceEpsListener` — listens to `SkuRetailPricingUpdatedEvent`, sets SellingPriceGross + SellingPriceNet
- `UpdateShopwiredSaleStateListener` — listens to sale events, manages ShopWired category + custom fields + sort order
- `UpdateLinnworksSaleStateListener` — listens to sale events, manages is_in_sale + last_sale_end_date EPs
- `UpdateProductCustomFieldClientInterface` — application-layer contract for writing custom fields to ShopWired
- `CheckExpiredSalesUseCase` — queries local DB for products needing auto-removal, calls `UpdateProductPricesUseCase` per match
- `CheckExpiredSalesJob` — queued job dispatched by scheduler
- Config entry: `shopwired.sale_category_id` (value: `64939`)

## Feature Specifications

### 1. Selling Price → Linnworks EPs

**Requirement:** When a selling price is confirmed updated on ShopWired, sync SellingPriceGross and SellingPriceNet to Linnworks.

**Architecture:** Event-driven. New listener on `SkuRetailPricingUpdatedEvent`.

**Integration:**
- Linnworks `UpdateInventoryItemExtendedProperties` / `CreateInventoryItemExtendedProperties` API (via new `setExtendedProperty()`)
- EP names: `SellingPriceGross` (gross price as string), `SellingPriceNet` (calculated from gross via `ProductRetailPricing::taxType()`)

**Data flow:**
- `SkuRetailPricingUpdatedEvent` → listener extracts `newPrices.effectivePrice().toGross()` for gross
- Net calculated from gross using tax type (standard 20% UK VAT)
- Calls `setExtendedProperty()` twice per SKU (gross + net)

**Error handling:** Queued listener with retry. TransientApiFailure → retry, PermanentApiFailure → fail. Same pattern as existing listeners.

### 2. Sale State Detection

**Requirement:** When a price update results in a product entering or leaving sale, dispatch sale-specific events carrying sale metadata.

**Architecture:** Event-driven. New listener on `ProductPricingUpdatedEvent` that inspects `SkuPriceChange` transitions.

**Data flow:**
- `ProductPricingUpdatedEvent` carries `list<SkuPriceChange>` + optional `?SaleSettings`
- Listener checks if ANY `SkuPriceChange` has `addedToSale()` or `removedFromSale()`
- If so, dispatches `ProductAddedToSaleEvent` or `ProductRemovedFromSaleEvent` with the `SaleSettings` context

**`SaleSettings` VO (Domain):**
```
?string $saleReason
?string $saleComments
?DateTimeImmutable $saleEndDate
?int $saleEndsStock
?SaleRemovalReason $removalReason
```

All fields nullable. For manual add-to-sale: reason/comments/dates populated. For auto-removal: removalReason populated. For selling-price-only changes: null (no sale events dispatched).

**Threading:** `SaleSettings` is threaded through `UpdateProductPricesUseCase` → `ProductPricingUpdatedEvent` → `DetectSaleStateChangeListener` → sale events.

### 3. Add to Sale → ShopWired State

**Requirement:** When a product is added to sale, update ShopWired: add to sale category, set sort order to 3 (preserving original), write sale metadata custom fields.

**Architecture:** Event-driven. New queued listener on `ProductAddedToSaleEvent`.

**Integration:** ShopWired REST API via existing clients:
- `ProductFieldUpdateClient::update()` for categories and sort order
- `UpdateProductCustomFieldClientInterface` (new) for custom fields

**Data flow:**
1. Fetch current product (need current categories + sort order)
2. Add sale category ID (from `config('shopwired.sale_category_id')`) to category list
3. If current sort order is null or > 3, set to 3; preserve original in `default_sort_order` custom field
4. Write sale custom fields: `sale_date_start`, `sale_date_end`, `sale_reason`, `sale_comments`, `sale_ends_stock`, `default_sort_order`

**Error handling:** Queued with retry. All ShopWired operations grouped in one listener for consistency — if one fails, all retry together.

### 4. Add to Sale → Linnworks EP

**Requirement:** When a product is added to sale, set `is_in_sale` EP to `'1'` on Linnworks.

**Architecture:** Event-driven. New queued listener on `ProductAddedToSaleEvent`.

**Integration:** `setExtendedProperty(sku, ExtendedPropertyName::IsInSale, '1')`

**Error handling:** Queued with retry, independent of ShopWired listener.

### 5. Remove from Sale → ShopWired State

**Requirement:** When a product is removed from sale, restore ShopWired: remove sale category, restore original sort order, clear sale custom fields.

**Architecture:** Event-driven. New queued listener on `ProductRemovedFromSaleEvent`.

**Data flow:**
1. Fetch current product (need current categories + custom fields for `default_sort_order`)
2. Remove sale category ID from category list
3. Restore sort order from `default_sort_order` custom field (or clear if no previous value)
4. Clear sale custom fields: set all 6 fields to `""` via merge-update

### 6. Remove from Sale → Linnworks EPs

**Requirement:** When a product is removed from sale, set `is_in_sale` to `'0'` and `last_sale_end_date` to current datetime. **Always** — fixes legacy bug where auto-removals skipped this.

**Architecture:** Event-driven. New queued listener on `ProductRemovedFromSaleEvent`.

**Integration:**
- `setExtendedProperty(sku, ExtendedPropertyName::IsInSale, '0')`
- `setExtendedProperty(sku, ExtendedPropertyName::LastSaleEndDate, now('Y-m-d H:i:s'))`

### 7. Enriched Slack Notification

**Requirement:** When a price update includes sale transitions, enrich the existing Slack notification with sale context.

**Architecture:** Extend existing `ProductPricingUpdatedNotification` and `sendPriceUpdateAlert()`.

**Data flow:**
- `SaleSettings` (if present) passed through to notification
- For `addedToSale()`: show sale reason, discount %, end date (if set), stock threshold (if set)
- For `removedFromSale()`: show removal reason (human-readable from `SaleRemovalReason` enum)
- Existing price change lines and `[SALE]`/`[SALE ENDED]` labels remain

### 8. Automatic Sale Removal Cron

**Requirement:** Periodically check for products that should be automatically removed from sale, then process removals through the standard pricing flow.

**Architecture:** Scheduled job → use case → `UpdateProductPricesUseCase`.

**Scheduling:** Register in `ShopwiredScheduleServiceProvider`. Frequency TBD (legacy ran as cron — likely every 15–30 minutes).

**Data flow:**
1. `CheckExpiredSalesUseCase` queries local DB for products where `salePrice > 0`
2. For each product, evaluate 4 removal conditions from local data (product active status, `sale_date_end` custom field, stock level, `sale_ends_stock` custom field, `discontinued` custom field)
3. For each match, call `UpdateProductPricesUseCase(sku, salePrice: Money(0), saleSettings: SaleSettings(removalReason: ...))`
4. Standard event chain fires: pricing events → sale state detection → sale removal side-effects

**Removal conditions:**
| Condition | Removal Reason | Logic |
|-----------|---------------|-------|
| Product inactive | `product_inactive` | `active === false` |
| Sale end date passed | `end_date_reached` | `sale_date_end` is non-empty and ≤ today |
| Out of stock + discontinued | `out_of_stock_discontinued` | `stock === 0` AND `discontinued` is non-empty |
| Sale units sold | `sale_units_sold` | `stock <= sale_ends_stock` (fixes legacy bug that used wrong reason) |

**Error handling:** Job with retry. Per-product failures should not block other removals (continue-on-failure pattern).

### 9. EP Infrastructure: setExtendedProperty

**Requirement:** Replace `addExtendedProperty` (create-only) with `setExtendedProperty` (read→compare→create-or-update) on `InventoryUpdateClientInterface`.

**Architecture:** Infrastructure client change.

**Behaviour (matching legacy `epsByKeyVal()`):**
1. Read all current EPs for the stock item via `getInventoryItemExtendedProperties` API
2. Compare: if EP exists and value matches, skip (no write)
3. If EP exists and value differs, update via `UpdateInventoryItemExtendedProperties`
4. If EP doesn't exist, create via `CreateInventoryItemExtendedProperties`

**Batch consideration:** Accept multiple name-value pairs in a single call to avoid repeated reads. Interface: `setExtendedProperties(Sku|Guid $identifier, array<string, string> $properties): void`.

## Decisions Log

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | Selling price EP listener triggers on `SkuRetailPricingUpdatedEvent` (per-SKU) | Maps 1:1 to EP updates. Each SKU's pricing is available in the event. Net price calculated from `ProductRetailPricing::taxType()`. |
| D2 | Sale state changes use SEPARATE domain events (`ProductAddedToSaleEvent`, `ProductRemovedFromSaleEvent`) | Sale side-effects (category, custom fields, sort order) are distinct from pricing. Auto-removal cron also needs these events. Cleaner separation of concerns. |
| D3 | Sale events dispatched by a listener on `ProductPricingUpdatedEvent` that detects sale transitions | Single entry point: all price changes go through `UpdateProductPricesUseCase`. Sale metadata threaded via `SaleSettings` VO on the event. |
| D4 | Auto-removal cron uses same `UpdateProductPricesUseCase` flow | Consistent single-path architecture. Cron is "just another caller" that clears salePrice with a removal reason in `SaleSettings`. |
| D5 | Enrich existing Slack notification rather than creating separate sale notifications | Avoids duplicate notifications. Existing notification already labels sale transitions. Add context from `SaleSettings` when present. |
| D6 | Replace `addExtendedProperty` with `setExtendedProperty` (create-or-update) | Legacy `epsByKeyVal()` does read→compare→create-or-update. Current method only creates. New method needed for updating existing EPs. |
| D7 | Sale category ID in config (`shopwired.sale_category_id`), not domain constant | Platform-specific concern, not a business rule. |
| D8 | No console command for auto-removal — direct job dispatch via scheduler | `Schedule::job(new CheckExpiredSalesJob())` is sufficient. Console command is unnecessary indirection. |
| D9 | Sale custom fields: write to ShopWired via new `UpdateProductCustomFieldClientInterface`, read from local DB | Writing needs ShopWired API. Reading for auto-removal cron uses local DB (already synced). |
| D10 | Per-system listeners for sale events (one ShopWired, one Linnworks) | Groups related operations for consistency — if ShopWired category update fails, custom fields retry together. Linnworks EP independent. |
| D11 | `SaleSettings` is a Domain VO, `SaleRemovalReason` is a Domain enum | Carried by domain events, used by domain logic (detection). Framework-independent. |

## Proposed Implementation

### Domain Layer

```
Domain/Catalog/Product/
├── Events/
│   ├── ProductAddedToSaleEvent.php      # NEW: IntId $productId, SaleSettings $settings
│   └── ProductRemovedFromSaleEvent.php  # NEW: IntId $productId, SaleSettings $settings
├── ValueObjects/
│   └── SaleSettings.php                 # NEW: nullable sale metadata VO
└── Enums/
    └── SaleRemovalReason.php            # NEW: manual, product_inactive, end_date_reached, etc.

Domain/Inventory/Enums/
└── ExtendedPropertyName.php             # EXTEND: add SellingPriceGross, SellingPriceNet, IsInSale, LastSaleEndDate

Domain/Catalog/Product/ValueObjects/
└── ProductFieldUpdate.php               # EXTEND: add sortOrder() factory

Domain/Catalog/Product/Enums/
└── ProductUpdatableField.php            # EXTEND: add SortOrder case
```

### Application Layer

```
Application/Contracts/Shopwired/
└── UpdateProductCustomFieldClientInterface.php  # NEW: updateCustomFields(int $productId, array $fields): void

Application/Contracts/Linnworks/
└── InventoryUpdateClientInterface.php           # MODIFY: replace addExtendedProperty → setExtendedProperties

Application/Shopwired/SaleManagement/
└── UseCases/
    └── CheckExpiredSalesUseCase.php              # NEW: query DB, evaluate conditions, dispatch price updates
```

### Infrastructure Layer

```
Infrastructure/Linnworks/Clients/
└── InventoryUpdateClient.php                    # MODIFY: implement setExtendedProperties (read→compare→create/update)

Infrastructure/Shopwired/Listeners/
├── UpdateShopwiredSaleStateListener.php         # NEW: category + custom fields + sort order
└── DetectSaleStateChangeListener.php            # NEW: inspect SkuPriceChange, dispatch sale events

Infrastructure/Linnworks/Listeners/
├── UpdateLinnworksSellingPriceEpsListener.php   # NEW: SellingPriceGross + SellingPriceNet
└── UpdateLinnworksSaleStateListener.php         # NEW: is_in_sale + last_sale_end_date

Infrastructure/Jobs/Shopwired/
└── CheckExpiredSalesJob.php                     # NEW: scheduled job for auto-removal

Infrastructure/Notifications/Slack/
└── ProductPricingUpdatedNotification.php        # MODIFY: enrich with SaleSettings context
```

### Config

```
config/shopwired.php                             # ADD: 'sale_category_id' => env('SHOPWIRED_SALE_CATEGORY_ID', 64939)
```

### Event Registration (EventServiceProvider)

```php
Event::listen(SkuRetailPricingUpdatedEvent::class, UpdateLinnworksSellingPriceEpsListener::class);
Event::listen(ProductPricingUpdatedEvent::class, DetectSaleStateChangeListener::class);
Event::listen(ProductAddedToSaleEvent::class, UpdateShopwiredSaleStateListener::class);
Event::listen(ProductAddedToSaleEvent::class, UpdateLinnworksSaleStateListener::class);
Event::listen(ProductRemovedFromSaleEvent::class, UpdateShopwiredSaleStateListener::class);
Event::listen(ProductRemovedFromSaleEvent::class, UpdateLinnworksSaleStateListener::class);
```
