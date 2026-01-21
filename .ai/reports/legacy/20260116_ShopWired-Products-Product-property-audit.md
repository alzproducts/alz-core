# Property Usage Audit: \ShopWired\Products\Product

**Date:** 2026-01-16
**Source File:** `legacy/src/ShopWired/src/Products/Product.php`
**Full Path:** `/Users/tom/code/alz-connect/legacy/src/ShopWired/src/Products/Product.php`
**Total Properties:** 58

---

## Summary

| Classification | Count | Percentage |
|----------------|-------|------------|
| ESSENTIAL (10+ usages) | 5 | 8.6% |
| IMPORTANT (3-9 usages) | 10 | 17.2% |
| LOW USE (1-2 usages) | 15 | 25.9% |
| UNUSED (0 external usages) | 28 | 48.3% |

---

## ESSENTIAL Properties (10+ usages)

These properties are core to business logic and must be retained.

| Property | PHP Type | Usage Count | Key Usages |
|----------|----------|-------------|------------|
| `id` | `int\|null` | 15+ | ProductProfitCategoryManager, LwSwEpIdCheck, SwId, ProductApiService, UpdateSellingPriceFormService, listeners |
| `sku` | `string` | 30+ | ProductProfitCategoryManager, LwSwEpIdCheck, SwId, ProductApiService, SellingPriceMainController, listeners |
| `title` | `string` | 20+ | ProductProfitCategoryManager, ProductApiService, UpdateSellingPriceFormService, CustomFieldsGroup, listeners |
| `url` | `string` | 12+ | ProductApiService, UpdateSellingPriceFormService, CustomFieldsGroup, ProductAddedToSaleListener, ProductRemovedFromSaleListener, SlackMessageProductCreatedListener |
| `variations` | `Variation[]\|Collection` | 10+ | ProductProfitCategoryManager, ProductHelper, ProductDiscontinueListener, ProductFunct, SwId, AbstractUniPropAjaxForm |

---

## IMPORTANT Properties (3-9 usages)

These properties are regularly used in business operations.

| Property | PHP Type | Usage Count | Key Usages |
|----------|----------|-------------|------------|
| `active` | `bool` | 4 | AbstractUniPropAjaxForm, ProductDiscontinueListener, CreateSwProductModel, ToDoA |
| `description` | `string` | 5 | CreateSwProductModel, MainDescription, ToDoA |
| `price` | `float` | 8 | ProductApiService, UpdateSellingPriceFormService, ProductRemovedFromSaleListener, ProductAddedToSaleListener, GetSellingPrice, SlackMessageProductCreatedListener |
| `costPrice` | `float\|int` | 5 | ProductProfitModel, Variation extract |
| `salePrice` | `float\|int` | 4 | ProductApiService, UpdateSellingPriceFormService, ProductRemovedFromSaleListener, ProductAddedToSaleListener |
| `metaDescription` | `string\|null` | 3 | CreateSwProductModel, MetaDescription2, AdhocFuncts |
| `categories` | `Category[]\|Collection` | 6+ | UpdateSellingPriceFormService, PrefillData, Categories section, Category fieldgroup |
| `customFields` | `CustomField[]\|Collection` | 5 | ProductProfitCategoryManager, ProductRemovedFromSaleListener, CustomFieldsGroup, CustomFieldsSect |
| `vatExclusive` | `bool` | 4 | UpdateSellingPriceFormService, PrefillData, ProductFunct, ToDoA |
| `vatRate` | `int\|null` | 3 | ProductFunct, ItemFromProduct |

---

## LOW USE Properties (1-2 usages)

Edge case properties that may warrant review.

| Property | PHP Type | Usage Count | Key Usages |
|----------|----------|-------------|------------|
| `description2` | `string` | 1 | Video.php |
| `comparePrice` | `float\|null` | 1 | UpdateSellingPriceFormService |
| `weight` | `float\|int` | 2 | Variation extract, StockSection |
| `stock` | `int\|null` | 1 | Variation extract only |
| `metaTitle` | `string\|null` | 1 | ExampleMetaTitle |
| `slug` | `string` | 1 | CreateSwProductModel |
| `googleCategory` | `int\|null` | 1 | Category fieldgroup |
| `images` | `Image[]\|Collection` | 2 | ShopWiredA, SlackMessageProductCreatedListener |
| `brand` | `Brand\|null` | 1 | Main section |
| `options` | `Option[]\|Collection` | 2 | UpdateSellingPriceFormService, AbstractUniPropAjaxForm |
| `filters` | `array\|null` | 1 | FilterSection |
| `related` | `array` | 1 | RelatedProducts field |
| `sortOrder` | `int` | 1 | ProductAddedToSaleListener |
| `vatRelief` | `bool` | 2 | PrefillData, SlackMessageProductCreatedListener |
| `warehouseNotes` | `string` | 1 | Order\Product model only |
| `gtin` | `string` | 1 | Variation extract only |
| `mpn` | `string` | 0 | (usages found are from other classes) |
| `rewardPoints` | `int\|null` | 1 | Variation extract only |

---

## UNUSED Properties (0 external usages)

These properties have zero external usages outside the Product class itself.

### Removal Candidates (Safe to Remove)

| Property | PHP Type | Notes |
|----------|----------|-------|
| `description3` | `string` | Extra description field - never used |
| `description4` | `string` | Extra description field - never used |
| `description5` | `string` | Extra description field - never used |
| `bundle` | `bool` | Boolean flag - never checked |
| `new` | `bool` | Boolean flag - never checked |
| `twoForOne` | `bool` | Promotional flag - never checked |
| `threeForTwo` | `bool` | Promotional flag - never checked |
| `excludedFromTradeDiscounts` | `bool` | Never accessed |
| `searchKeywords` | `string` | Never accessed |
| `createdAt` | `string\|null` | Never accessed externally |
| `videoCode` | `string` | Never accessed |

### API Compatibility (Keep for ShopWired API)

| Property | PHP Type | Notes |
|----------|----------|-------|
| `deliveryPrice` | `float\|int` | May be set/read via API |
| `freeDelivery` | `bool` | May be set/read via API |
| `singleDeliveryPrice` | `bool\|null` | May be set/read via API |
| `googleCondition` | `int\|null` | Google Shopping integration |
| `googleIsBundle` | `bool` | Google Shopping integration |
| `googleNoIdentifierExists` | `bool` | Google Shopping integration |
| `eBayCategory` | `int\|null` | eBay integration |
| `eBayBestOffer` | `bool\|null` | eBay integration |
| `eBayShippingRates` | `EbayShippingRate[]\|Collection` | eBay integration |
| `extras` | `Extra[]\|Collection` | ShopWired product extras feature |
| `customizationFields` | `CustomizationField[]\|Collection` | ShopWired customization feature |
| `digitalFiles` | `DigitalFile[]\|Collection` | Digital product downloads |
| `fileUploadsAllowed` | `int\|null` | File upload feature |

---

## Recommendations

### 1. Properties Safe to Remove (11 properties)

```php
// These can be removed from the model:
$description3
$description4
$description5
$bundle
$new
$twoForOne
$threeForTwo
$excludedFromTradeDiscounts
$searchKeywords
$createdAt
$videoCode
```

### 2. Type Recommendations for New Schema

| Category | Recommended Type |
|----------|-----------------|
| IDs | `int` (non-nullable with default) or `int\|null` |
| Text fields | `string` (empty string default) |
| Prices | `float` or `\Brick\Money\Money` |
| Weights | `float` |
| Booleans | `bool` (with explicit default) |
| Dates | `\DateTimeImmutable\|null` |
| Collections | Typed arrays or custom Collection classes |

### 3. Priority Migration Order

1. **Phase 1 - Core identifiers:** id, sku, title, url
2. **Phase 2 - Pricing:** price, costPrice, salePrice, comparePrice
3. **Phase 3 - Relations:** variations, categories, images, customFields, brand
4. **Phase 4 - Metadata:** descriptions, meta fields, active
5. **Phase 5 - Integration fields:** Google, eBay (if still needed)
6. **Phase 6 - Remaining:** All other properties

### 4. Cleanup Actions

- [ ] Remove 11 unused properties after confirming no API dependencies
- [ ] Add strict types to remaining properties
- [ ] Convert Collection to typed arrays or generics
- [ ] Consider splitting model: ProductCore vs ProductApiData

---

## File References

Key files using this model:
- `legacy/src/AlzMvc/Application/Service/Product/Pricing/Margin/ProductProfitCategoryManager.php`
- `legacy/src/Mvc/Service/Shopwired/Product/ProductApiService.php`
- `legacy/src/Mvc/Controller/SellingPrice/UpdateSellingPriceFormService.php`
- `legacy/src/AlzMvc/Listeners/Product/Pricing/*.php`
- `legacy/src/Api/AlzApi/LinkedItem/Product/LwSwLinkId/SwId.php`
- `legacy/src/NewProduct/Form/**/*.php`
