# Implementation Log: #346 Port Price Update Side-Effects

**Issue:** #346
**Branch:** `feature/346-port-price-update-side-effects`
**Plan:** `.ai/plans/2026-03-23_346-port-price-update-side-effects.md`

## Decision Log

| # | Decision | Rationale |
|---|----------|-----------|
| D1 | `DetectSaleStateChangeListener` delegates to `SaleStateDetectionService` in Application | PHPStan custom rule `NoEventDispatchOutsideApplicationRule` bans event dispatch from Infrastructure. Detection = business logic, belongs in Application. |
| D2 | Sort order preservation skipped on add-to-sale | Product VO lacks `sortOrder` property. Adding it cascades through factory/DTO/model. Deferred — always sets sort order to 3, restores from `default_sort_order` custom field on removal. |
| D3 | Job renamed `ProcessExpiredSalesJob` (not `Check*`) | Custom PHPStan rule `alz.jobNamingPrefix` requires prefix from: Sync, Process, Reconcile, Set, Update, Cleanup. |
| D4 | Listeners use `HandleApiExceptions` middleware | Unlike existing Slack listeners (which call through `ChatNotificationInterface`), new listeners make direct API calls. Middleware gives proper TransientApiFailure retry delay + PermanentApiFailure immediate fail. |
| D5 | `CheckExpiredSalesUseCase` uses batch continue-on-failure | Per-product failures don't block other removals. Catches `Exception` with `@ignoreException` (approved Application batch pattern). |

## Files Created

- `app/Domain/Catalog/Product/Enums/SaleRemovalReason.php`
- `app/Domain/Catalog/Product/ValueObjects/SaleSettings.php`
- `app/Domain/Catalog/Product/Events/ProductAddedToSaleEvent.php`
- `app/Domain/Catalog/Product/Events/ProductRemovedFromSaleEvent.php`
- `app/Application/Shopwired/SaleManagement/Services/SaleStateDetectionService.php`
- `app/Application/Shopwired/UseCases/CheckExpiredSalesUseCase.php`
- `app/Infrastructure/Shopwired/Listeners/DetectSaleStateChangeListener.php`
- `app/Infrastructure/Shopwired/Listeners/UpdateShopwiredSaleStateListener.php`
- `app/Infrastructure/Linnworks/Listeners/UpdateLinnworksSellingPriceEpsListener.php`
- `app/Infrastructure/Linnworks/Listeners/UpdateLinnworksSaleStateListener.php`
- `app/Infrastructure/Jobs/Shopwired/ProcessExpiredSalesJob.php`

## Files Modified

- `app/Domain/Inventory/Enums/ExtendedPropertyName.php` — 4 new EP cases
- `app/Domain/Catalog/Product/Enums/ProductUpdatableField.php` — SortOrder case
- `app/Domain/Catalog/Product/ValueObjects/ProductFieldUpdate.php` — sortOrder() factory
- `app/Domain/Catalog/Product/Events/ProductPricingUpdatedEvent.php` — ?SaleSettings param
- `app/Application/Contracts/Linnworks/InventoryUpdateClientInterface.php` — setExtendedProperties()
- `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` — getProductsOnSale()
- `app/Application/Contracts/ChatNotificationInterface.php` — ?SaleSettings param
- `app/Application/Shopwired/PricingUpdate/UseCases/UpdateProductPricesUseCase.php` — ?SaleSettings threading
- `app/Infrastructure/Linnworks/Clients/InventoryUpdateClient.php` — setExtendedProperties impl
- `app/Infrastructure/Shopwired/Clients/ProductFieldUpdateClient.php` — SortOrder mapping
- `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` — getProductsOnSale()
- `app/Infrastructure/Notifications/SlackChatNotificationClient.php` — SaleSettings pass-through
- `app/Infrastructure/Notifications/Slack/ProductPricingUpdatedNotification.php` — sale context enrichment
- `app/Infrastructure/Notifications/Listeners/ProductPricingUpdatedSlackListener.php` — saleSettings threading
- `app/Providers/EventServiceProvider.php` — 6 new listener registrations
- `app/Providers/Schedule/ShopwiredScheduleServiceProvider.php` — expired sales schedule
- `config/shopwired.php` — sale_category_id

## PR Notes

All 5 linters pass. All 2477 tests pass.
