# Property Audit: `\Linn2\Model\Order` (Deep)

**Date:** 2026-03-15
**File:** `legacy/src/Api/Linn2/src/Model/Order.php`
**Extends:** `Linn2\Contract\AbstractBase` (uses `HydratesData` trait)

---

## Top-Level Summary

| Classification | Count |
|---|---|
| ESSENTIAL (10+) | 6 |
| IMPORTANT (3-9) | 5 |
| LOW USE (1-2) | 2 |
| UNUSED (0) | 6 |
| **Total Properties** | **19** |

---

## 1. Order Top-Level Properties

### ESSENTIAL (10+ usages)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `OrderId` | `string` (GUID) | `getOrderId()` | ~24 | CheckOneQb, AlzOrder, OrderApiService, SendLateEmailService, ExtendedPropertyManager, PaymentMethodUpdater, DeliveryDateUpdaterService, OrderCompletionManager, OrderSnapshot, multiple listeners |
| `NumOrderId` | `int` | `getNumOrderId()` | ~20 | OrderApiService (many), CheckVatReliefFormService, AssertNotOrderOpen/Cancelled/HasPoNumber, TemplateDataModel, HsTableEmailsForOrder, PaymentMethodUpdater, PoDelDatesByOrderService |
| `GeneralInfo` | `GeneralInfo` | `getGeneralInfo()` | ~40+ | See detailed breakdown below |
| `TotalsInfo` | `TotalsInfo` | `getTotalsInfo()` | ~12 | See detailed breakdown below |
| `ShippingInfo` | `ShippingInfo` | `getShippingInfo()` | ~13 | See detailed breakdown below |
| `CustomerInfo` | `CustomerInfo` | `getCustomerInfo()` | ~11 | See detailed breakdown below |

### IMPORTANT (3-9 usages)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `Processed` | `bool` | `isProcessed()` | 3 | LwOrderFunct (processed check, is-processed, is-not-processed) |
| `FulfilmentLocationId` | `string` (GUID) | `getFulfilmentLocationId()` | 4 | LwOrderFunct, OrderApiService, AlzOrderUpdate (x2) |
| `FolderName` | `string[]` | `getFolderName()` | 3 | Order Endpoint (x2), LwOrderFunct |
| `Items` | `Item[]\|Collection` | `getItems()` | 3 | OrderApiService, ProductListService (x2). See detailed Item breakdown below |
| `ProcessedDateTime` | `string\|null` | `getProcessedDateTime()` | 3 | LwOrderFunct (dispatch date calculation with timezone parsing) |

### LOW USE (1-2 usages)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `Notes` | `Note[]\|Collection` | `getNotes()` | 1 | OrderApiService:191 (wraps into noteCol). See Note breakdown below |
| *(computed)* | `?int` | `getShopId()` | 1 | AlzOrder:267 |

### UNUSED (0 getter usages)

| Property | PHP Type | Getter | Hydrated | Notes |
|---|---|---|---|---|
| `TaxInfo` | `array` | `getTaxInfo()` | Explicit in `hydrate()` | Populated from API, never read |
| `ExtendedProperties` | `EP[]\|Collection` | `getExtendedProperties()` | Explicit in `hydrate()` | EPs managed via separate API endpoints. See EP breakdown below |
| `IsPostFilteredOut` | `bool` | `isIsPostFilteredOut()` | Auto (parent) | Linnworks API field |
| `CanFulfil` | `bool` | `isCanFulfil()` | Auto (parent) | Linnworks API field |
| `HasItems` | `bool` | `isHasItems()` | Auto (parent) | Redundant with Items collection |
| `TotalItemsSum` | `int` | `getTotalItemsSum()` | Auto (parent) | Redundant - computable from Items |
| `StockAllocationType` | `string` | `getStockAllocationType()` | Auto (parent) | Linnworks API field |

### Static & Computed Methods

| Method | Usages | Key Consumers |
|---|---|---|
| `isFolderAssigned()` (static) | 9 | DropshipFolderChecks (x3), FolderChecks (x3), OrderApiService (wrapper), OrderImportApiService, UpdateCustomerFormService |
| `getShopId()` (computed) | 1 | AlzOrder:267 (derives ShopWired ID from GeneralInfo refs) |

---

## 2. GeneralInfo Sub-Entity (20 properties)

**File:** `legacy/src/Linnworks/Model/Order/GeneralInfo.php`
**Access pattern:** `$order->getGeneralInfo()->getXxx()`

### ESSENTIAL (10+)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `ReferenceNum` | `string` | `getReferenceNum()` | ~30+ | FullyLoadedOrderService, OrderApiService, PreCreateRefundAsserts, QueryEmailsOrderNotCustomer, QueryEmailsCustomerOrder, RefundProcessAssertService, SendInvoiceFormController, UpdateCustomerFormService, TemplateDataModel, SubjectFactory (x6), HsTableEmailsForOrder, CreditUpdateInitHelpscoutConvo, OrderImportedListener, QuickbooksTrackingUpdateListener, CreateRefundReceiptFactory, CheckOneQb, AlzOrder, HasDuplicates, Order.php (getShopId) |

### IMPORTANT (3-9)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `HoldOrCancel` | `bool` | `isHoldOrCancel()` | 4 | Order Endpoint, LwOrderFunct, OrderSnapshot, StatusSnapByModel |
| `Marker` | `int\|null` | `getMarker()` | 3 | Order Endpoint, OrderSnapshot, StatusSnapByModel |
| `Location` | `string` (GUID) | `getLocation()` | 3 | Order.php (getFulfilmentLocationId fallback), ItemAdder, BinRack |
| `ExternalReferenceNum` | `string` | `getExternalReferenceNum()` | 2 | CreditUpdateInitHelpscoutConvo, AssertHasPoNumber |
| `Status` | `int` | `getStatus()` | 2 | LwOrderFunct (status===1, status===4). *Note: many other getStatus() calls are on different classes* |

### LOW USE (1-2)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `SecondaryReference` | `string` | `getSecondaryReference()` | 2 | Order.php (getShopId), AlzOrder |
| `DespatchByDate` | `string` | `getDespatchByDate()` | 1 | UpdateCustomerFormService. *Also written via setDespatchByDate in DeliveryDateUpdaterService* |
| `ReceivedDate` | `string` | `getReceivedDate()` | 1 | LwOrderFunct (date parsing) |
| `Source` | `string` | `getSource()` | 1 | LwOrderFunct (DATAIMPORTEXPORT check) |
| `SubSource` | `string` | `getSubSource()` | 1 | LwOrderFunct (ShopWired check) |
| `IsParked` | `bool` | `isIsParked()` | 1 | StatusSnapByModel |

### UNUSED (0 getter usages)

| Property | PHP Type | Getter | Notes |
|---|---|---|---|
| `LabelPrinted` | `bool` | `isLabelPrinted()` | Print status flags - Linnworks internal |
| `LabelError` | `string\|null` | `getLabelError()` | |
| `InvoicePrinted` | `bool` | `isInvoicePrinted()` | |
| `PickListPrinted` | `bool` | `isPickListPrinted()` | |
| `IsRuleRun` | `bool` | `isIsRuleRun()` | |
| `Notes` | `int` | `getNotes()` | Note count (int), not the Note[] collection |
| `PartShipped` | `bool` | `isPartShipped()` | |
| `HasScheduledDelivery` | `bool` | `isHasScheduledDelivery()` | |
| `NumItems` | `int` | `getNumItems()` | Redundant with Items collection |

**GeneralInfo verdict:** Of 20 properties, only **ReferenceNum** is heavily used. 5 properties are moderately used for status/location checks. **9 properties are completely unused.**

---

## 3. TotalsInfo Sub-Entity (11 properties)

**File:** `legacy/src/Linnworks/Model/Order/TotalsInfo.php`
**Access pattern:** `$order->getTotalsInfo()->getXxx()`

### IMPORTANT (3-9)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `TotalCharge` | `float` | `getTotalCharge()` | 4 | HasMatchingOrderTotals, RefundProcessAssertService, AlzOrderUpdate, HasDuplicates |
| `PaymentMethod` | `string` | `getPaymentMethod()` | 2 | OrderImportApiService ('Default' check), OrderChecks. *Note: many getPaymentMethod() calls are on other classes* |
| `Subtotal` | `float` | `getSubtotal()` | 2 | HasDuplicates, LwOrderFunct |

### LOW USE (1-2)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `Tax` | `float` | `getTax()` | 1 | HasMatchingOrderTotals |
| `PaymentMethodId` | `string` (GUID) | `getPaymentMethodId()` | 0 | *Written via setPaymentMethodId in PaymentMethodUpdater, but getter never called externally* |

### UNUSED (0 getter usages)

| Property | PHP Type | Getter | Notes |
|---|---|---|---|
| `pkOrderId` | `string` (GUID) | `getPkOrderId()` | Only used in UpdateGeneral (different context) |
| `PostageCost` | `float` | `getPostageCost()` | Postage costs tracked via ShippingInfo instead |
| `Profit` | `int` | `getProfit()` | |
| `TotalDiscount` | `float` | `getTotalDiscount()` | |
| `Currency` | `string` | `getCurrency()` | |
| `CountryTaxRate` | `float` | `getCountryTaxRate()` | |
| `ConversionRate` | `float` | `getConversionRate()` | |

**TotalsInfo verdict:** Of 11 properties, only **TotalCharge, PaymentMethod, Subtotal** are meaningfully used. **7 properties are completely unused.**

---

## 4. ShippingInfo Sub-Entity (13 properties)

**File:** `legacy/src/Linnworks/Model/Order/ShippingInfo.php`
**Access pattern:** `$order->getShippingInfo()->getXxx()`

### IMPORTANT (3-9)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `PostalServiceName` | `string` | `getPostalServiceName()` | 5 | LoadedOrderTraits, ShippingUpdater, GetCourierNameService (x2), HasPriorityShippingMethod |
| `PostageCostExTax` | `float` | `getPostageCostExTax()` | 3 | LwOrderFunct (tax calc, cost comparison, subtotal calc) |

### LOW USE (1-2)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `Vendor` | `string` | `getVendor()` | 2 | LoadedOrderTraits, GetCourierNameService |
| `PostageCost` | `float` | `getPostageCost()` | 2 | LwOrderFunct (tax calc, cost comparison) |
| `TrackingNumber` | `string` | `getTrackingNumber()` | 1 | LwOrderFunct. *Note: other getTrackingNumber() calls are on events, not ShippingInfo* |

### UNUSED (0 getter usages)

| Property | PHP Type | Getter | Notes |
|---|---|---|---|
| `PostalServiceId` | `string` (GUID) | `getPostalServiceId()` | |
| `TotalWeight` | `float` | `getTotalWeight()` | |
| `ItemWeight` | `float` | `getItemWeight()` | |
| `PackageCategoryId` | `string` (GUID) | `getPackageCategoryId()` | |
| `PackageCategory` | `string` | `getPackageCategory()` | |
| `PackageTypeId` | `string` (GUID) | `getPackageTypeId()` | |
| `PackageType` | `string` | `getPackageType()` | |
| `ManualAdjust` | `bool` | `isManualAdjust()` | |

**ShippingInfo verdict:** Of 13 properties, only **PostalServiceName** and **PostageCostExTax** are moderately used. **8 properties are completely unused.**

---

## 5. CustomerInfo Sub-Entity (3 properties + Address sub-entities)

**File:** `legacy/src/Linnworks/Model/Order/CustomerInfo/CustomerInfo.php`
**Access pattern:** `$order->getCustomerInfo()->getXxx()`

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `Address` | `Address` | `getAddress()` | 6 | LwOrderFunct, HasDuplicates, CalcByDropshipOrder, CreditUpdateInitHelpscoutConvo, CustomerInfoUpdater, AssertHasNoVatOnInternational |
| `BillingAddress` | `Address` | `getBillingAddress()` | 4 | CustomerInfoUpdater, LwOrderFunct, RefundReceiptDescriptionGenerator (x2), CustomerInfoMapper |
| `ChannelBuyerName` | `string` | `getChannelBuyerName()` | 1 | CustomerInfoUpdater |

### Address Sub-Entity (12 properties)

**File:** `legacy/src/Linnworks/Model/Order/CustomerInfo/Address.php`
**Access pattern:** `$order->getCustomerInfo()->getAddress()->getXxx()`

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `EmailAddress` | `string` | `getEmailAddress()` | 5+ | HasDuplicates, HsTableEmailsForOrder, CreditUpdateInitHelpscoutConvo, LwOrderFunct, RefundReceiptDescriptionGenerator, HelpScoutLateEmailFormService (x3) |
| `Country` | `string` | `getCountry()` | 4 | FormattedDeliveryAddress, CalcByDropshipOrder, AssertHasNoVatOnInternational, CreateAddressFromShop |
| `FullName` | `string` | `getFullName()` | 5 | LwOrderFunct (x4 - shipping/billing name comparison), FormattedDeliveryAddress |
| `PostCode` | `string` | `getPostCode()` | 2 | RefundReceiptDescriptionGenerator, FormattedDeliveryAddress |
| `Company` | `string` | `getCompany()` | 2 | FormattedDeliveryAddress (x2) |
| `Address1` | `string` | `getAddress1()` | 1 | FormattedDeliveryAddress |
| `Address2` | `string` | `getAddress2()` | 2 | FormattedDeliveryAddress (x2, with empty check) |
| `Address3` | `string` | `getAddress3()` | 2 | FormattedDeliveryAddress (x2, with empty check) |
| `Town` | `string` | `getTown()` | 1 | FormattedDeliveryAddress |
| `Region` | `string` | `getRegion()` | 0 | **UNUSED** |
| `PhoneNumber` | `string` | `getPhoneNumber()` | 0 | **UNUSED** |
| `CountryId` | `string` | `getCountryId()` | 0 | **UNUSED** |

**CustomerInfo verdict:** Address and BillingAddress are well-used containers. EmailAddress, Country, FullName are the most consumed address fields. **Region, PhoneNumber, CountryId are unused.**

---

## 6. Item Sub-Entity (50 properties)

**File:** `legacy/src/Linnworks/Model/Order/Items/Item.php`
**Access pattern:** `$order->getItems()` then iterate; also used heavily as standalone StockItem model

### ESSENTIAL (10+)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `Title` | `string` | `getTitle()` | 24 | ProductProfitCategoryManager, SellingPriceFormService, ProductApiService, ProductListService, multiple Listeners, ReturnsTable, Form sections |
| `StockItemId` | `string` (GUID) | `getStockItemId()` | 24 | UpdatePricesFromFormService, AbstractUniPropForm, AlzOrderUpdate, OrderApiService, UniqueProps, CostPrice services, UpdateAllShopEpsService, multiple Listeners, StockItem/Create, PrefillData |
| `ItemNumber` | `string` | `getItemNumber()` | 21 | AlzOrderUpdate, UpdatePricesFromFormService, AbstractUniPropAjaxForm, LwSwEpId, UpdateAllShopEpsService, multiple Listeners, CustomFieldsGroup, StockItem/Create, PrefillData |

### IMPORTANT (3-9)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `Quantity` | `int` | `getQuantity()` | 6 | BinRack, ProductListService, AlzOrderUpdate, Product, SlackMessageProductCreatedListener, ItemFromProduct |
| `TaxRate` | `float` | `getTaxRate()` | 6 | AlzOrderUpdate, UpdatePricesFromFormService, AddItem (x2), ProductProfitModel, GetTaxRateBySku |
| `AdditionalInfo` | `AdditionalInfo[]\|Collection` | `getAdditionalInfo()` | 5 | AlzOrder, AlzOrderUpdate (x4) |
| `SKU` | `string` | `getSKU()` | 4 | AlzOrderUpdate (x2), ProductListService (x2) |
| `Weight` | `float` | `getWeight()` | 4 | Product, StockSection (x2), Variation |

### LOW USE (1-2)

| Property | PHP Type | Getter | Usages | Key Consumers |
|---|---|---|---|---|
| `RowId` | `string` (GUID) | `getRowId()` | 2 | AlzOrderUpdate (x2) - *other getRowId calls are on different EP classes* |
| `StockItemIntId` | `int` | `getStockItemIntId()` | 1 | AbstractStockItemSupplierStat. *(1 additional usage is commented out)* |
| `PricePerUnit` | `float` | `getPricePerUnit()` | 1 | AlzOrderUpdate |
| `Discount` | `float` | `getDiscount()` | 1 | AlzOrderUpdate |
| `Cost` | `float` | `getCost()` | 1 | AddItem |
| `ChannelSKU` | `string` | `getChannelSKU()` | 1 | ProductListService |
| `Level` | `int` | `getLevel()` | 1 | StockSection |
| `AvailableStock` | `int` | `getAvailableStock()` | 1 | OrderApiService |
| `MinimumLevel` | `int` | `getMinimumLevel()` | 1 | PrefillData |
| `isTaxCostInclusive` | `bool` | `isTaxCostInclusive()` | 1 | AlzOrderUpdate |
| `isPartShipped` | `bool` | `isPartShipped()` | 1 | GeneralInfo |
| `BinRack` | `string` | `getBinRack()` | 1 | BinRack model |
| `BarcodeNumber` | `string` | `getBarcodeNumber()` | 0* | *3 usages all commented out in PrefillData* |

### UNUSED (0 getter usages) — 25 properties

| Property | PHP Type | Getter |
|---|---|---|
| `ItemId` | `string` (GUID) | `getItemId()` |
| `ItemSource` | `string` | `getItemSource()` |
| `CategoryName` | `string` | `getCategoryName()` |
| `ChannelTitle` | `string` | `getChannelTitle()` |
| `UnitCost` | `float` | `getUnitCost()` |
| `CostIncTax` | `float` | `getCostIncTax()` |
| `DespatchStockUnitCost` | `float` | `getDespatchStockUnitCost()` |
| `DiscountValue` | `float` | `getDiscountValue()` |
| `Tax` | `float` | `getTax()` |
| `SalesTax` | `float` | `getSalesTax()` |
| `ShippingCost` | `float` | `getShippingCost()` |
| `IsService` | `bool` | `isIsService()` |
| `StockLevelsSpecified` | `bool` | `isStockLevelsSpecified()` |
| `OnOrder` | `int` | `getOnOrder()` |
| `Market` | `int` | `getMarket()` |
| `HasImage` | `bool` | `isHasImage()` |
| `ImageId` | `string` (GUID) | `getImageId()` |
| `StockLevelIndicator` | `int` | `getStockLevelIndicator()` |
| `PartShippedQty` | `int` | `getPartShippedQty()` |
| `BatchNumberScanRequired` | `bool` | `isBatchNumberScanRequired()` |
| `SerialNumberScanRequired` | `bool` | `isSerialNumberScanRequired()` |
| `InventoryTrackingType` | `int` | `getInventoryTrackingType()` |
| `isBatchedStockItem` | `bool` | `isBatchedStockItem()` |
| `IsWarehouseManaged` | `bool` | `isIsWarehouseManaged()` |
| `HasPurchaseOrders` | `bool` | `isHasPurchaseOrders()` |
| `CanPurchaseOrderFulfil` | `bool` | `isCanPurchaseOrderFulfil()` |
| `IsUnlinked` | `bool` | `isIsUnlinked()` |
| `InOrderBook` | `int` | `getInOrderBook()` |
| `CompositeSubItems` | `Item[]\|Collection` | `getCompositeSubItems()` |
| `BinRacks` | `BinRack[]\|Collection` | `getBinRacks()` |
| `OrderId` | `string` (GUID) | `getOrderId()` |

**Item verdict:** Of **50 properties**, only **3 are essential** (Title, StockItemId, ItemNumber). **25 properties (50%) are completely unused.** Note: Item is used both as an order line item AND as a stock item model, so some "unused as order item" properties may be relevant for stock item contexts.

---

## 7. Note Sub-Entity (7 properties)

**File:** `legacy/src/Linnworks/Model/Order/Note.php`
**Access pattern:** Notes are created via factory methods and immediately serialized via `extract()`

| Property | PHP Type | Getter | External Usages |
|---|---|---|---|
| `OrderNoteId` | `string` (GUID) | `getOrderNoteId()` | 0 |
| `OrderId` | `string` (GUID) | `getOrderId()` | 0 |
| `NoteDate` | `string` | `getNoteDate()` | 0 |
| `Internal` | `bool` | `getInternal()` | 0 |
| `Note` | `string` | `getNote()` | 0 |
| `CreatedBy` | `string` | `getCreatedBy()` | 0 |
| `NoteTypeId` | `int\|null` | `getNoteTypeId()` | 0 |

**Creation patterns (3 total):**
- `Note::factory()` — 2 usages (OrderAdminNotes, OrderServiceTraits)
- `Note::factoryInternalProcessing()` — 1 usage (SwDelDateToLwNoteService)

**Note verdict:** **All 7 getters are unused externally.** Notes are write-only: created via factory, serialized via `extract()`, sent to Linnworks API as arrays. Individual property getters are never consumed.

---

## 8. ExtendedProperty Sub-Entity (4 properties)

**File:** `legacy/src/Linnworks/Model/Order/ExtendedProperty.php`
**Access pattern:** EPs are managed through separate Linnworks API endpoints, not read from the Order model

| Property | PHP Type | Getter | External Usages |
|---|---|---|---|
| `RowId` | `string` (GUID) | `getRowId()` | 0 (on Order EP class specifically) |
| `Name` | `string` | `getName()` | 0 (on Order EP class specifically) |
| `Value` | `string` | `getValue()` | 0 (on Order EP class specifically) |
| `Type` | `string` | `getType()` | 0 (on Order EP class specifically) |

**Note:** There is a separate `Linn2\Model\PurchaseOrder\ExtendedProperty` class that IS actively used. The Order EP class is hydrated from the API but properties are never read — EPs are managed through `AlzOrderUpdate->setExtendedProperties()` API calls directly.

**EP verdict:** **All 4 getters are unused externally on the Order context.** The Order model hydrates EPs from the API response but they're never read back from the model.

---

## Grand Total: Property Usage Across All Entities

| Entity | Total Props | Essential | Important | Low Use | Unused |
|---|---|---|---|---|---|
| **Order** (top-level) | 19 | 6 | 5 | 2 | 6 |
| **GeneralInfo** | 20 | 1 | 4 | 6 | 9 |
| **TotalsInfo** | 11 | 0 | 3 | 1 | 7 |
| **ShippingInfo** | 13 | 0 | 2 | 3 | 8 |
| **CustomerInfo** | 3 | 0 | 2 | 1 | 0 |
| **Address** | 12 | 0 | 3 | 5 | 3 (per address, x2) |
| **Item** | 50 | 3 | 5 | 13 | 25+4=29 |
| **Note** | 7 | 0 | 0 | 0 | 7 |
| **ExtendedProperty** | 4 | 0 | 0 | 0 | 4 |
| **TOTAL** | **139** | **10** | **24** | **31** | **73** |

**53% of all properties across the Order model tree are completely unused in application code.**

---

## Removal Candidates Summary

### Tier 1: Safe to Remove (unused, auto-hydrated only, no business logic)
Order: `IsPostFilteredOut`, `CanFulfil`, `HasItems`, `TotalItemsSum`, `StockAllocationType`
GeneralInfo: `LabelPrinted`, `LabelError`, `InvoicePrinted`, `PickListPrinted`, `IsRuleRun`, `PartShipped`, `HasScheduledDelivery`, `NumItems`, `Notes` (int)
TotalsInfo: `pkOrderId`, `PostageCost`, `Profit`, `TotalDiscount`, `Currency`, `CountryTaxRate`, `ConversionRate`
ShippingInfo: `PostalServiceId`, `TotalWeight`, `ItemWeight`, `PackageCategoryId`, `PackageCategory`, `PackageTypeId`, `PackageType`, `ManualAdjust`
Address: `Region`, `PhoneNumber`, `CountryId` (x2 for shipping + billing)
Item: 25+ properties (see Item UNUSED list above)

### Tier 2: Review Before Removing (hydrated but consumed only through extract/serialization)
Order: `TaxInfo`, `ExtendedProperties` (hydrated explicitly, never read)
Note: all 7 properties (class used as write-only DTO via factory + extract)
ExtendedProperty: all 4 properties (hydrated from API, never read back)

### Tier 3: Consider Consolidation
- `GeneralInfo.DespatchByDate` — only read once, written once (consider flattening to Order level)
- `GeneralInfo.Source`/`SubSource` — each used once for a ShopWired source check
- `TotalsInfo.PaymentMethodId` — written but never read (the string PaymentMethod is what's actually consumed)

---

## Recommendations for New Schema

### Core Order Table
```
orders:
  order_id          CHAR(36) PK    -- OrderId (GUID)
  num_order_id      INT UNSIGNED   -- NumOrderId
  processed         BOOLEAN
  processed_at      DATETIME       -- ProcessedDateTime (convert from string)
  fulfilment_loc_id CHAR(36)       -- FulfilmentLocationId (GUID)
  reference_num     VARCHAR(50)    -- GeneralInfo.ReferenceNum (most-used field)
  status            TINYINT        -- GeneralInfo.Status
  hold_or_cancel    BOOLEAN        -- GeneralInfo.HoldOrCancel
  marker            TINYINT NULL   -- GeneralInfo.Marker
  is_parked         BOOLEAN        -- GeneralInfo.IsParked
  location          CHAR(36)       -- GeneralInfo.Location (GUID)
  source            VARCHAR(50)    -- GeneralInfo.Source
  sub_source        VARCHAR(50)    -- GeneralInfo.SubSource
  received_date     DATETIME       -- GeneralInfo.ReceivedDate
  despatch_by_date  DATETIME       -- GeneralInfo.DespatchByDate
  secondary_ref     VARCHAR(100)   -- GeneralInfo.SecondaryReference
  external_ref      VARCHAR(100)   -- GeneralInfo.ExternalReferenceNum
  shop_id           INT NULL       -- Computed from SecondaryReference (promote to first-class)
```

### Order Totals (flattened into orders or separate table)
```
  total_charge      DECIMAL(10,2)  -- TotalsInfo.TotalCharge
  subtotal          DECIMAL(10,2)  -- TotalsInfo.Subtotal
  tax               DECIMAL(10,2)  -- TotalsInfo.Tax
  payment_method    VARCHAR(100)   -- TotalsInfo.PaymentMethod
```

### Order Shipping (flattened or separate table)
```
  postal_service_name  VARCHAR(100)  -- ShippingInfo.PostalServiceName
  vendor               VARCHAR(100)  -- ShippingInfo.Vendor
  postage_cost         DECIMAL(10,2) -- ShippingInfo.PostageCost
  postage_cost_ex_tax  DECIMAL(10,2) -- ShippingInfo.PostageCostExTax
  tracking_number      VARCHAR(100)  -- ShippingInfo.TrackingNumber
```

### Order Customer (separate table)
```
order_customers:
  order_id           CHAR(36) FK
  channel_buyer_name VARCHAR(200)
  -- shipping address fields:
  ship_email         VARCHAR(200)
  ship_full_name     VARCHAR(200)
  ship_company       VARCHAR(200)
  ship_address1-3    VARCHAR(200)
  ship_town          VARCHAR(100)
  ship_postcode      VARCHAR(20)
  ship_country       VARCHAR(100)
  -- billing address fields (same structure)
  bill_email, bill_full_name, etc.
```

### Order Items (separate table)
```
order_items:
  stock_item_id   CHAR(36)       -- Essential
  item_number     VARCHAR(50)    -- Essential
  title           VARCHAR(500)   -- Essential
  sku             VARCHAR(50)    -- Important
  quantity        INT            -- Important
  tax_rate        DECIMAL(5,2)   -- Important
  weight          DECIMAL(10,3)  -- Important
  price_per_unit  DECIMAL(10,2)
  discount        DECIMAL(10,2)
  cost            DECIMAL(10,2)
  row_id          CHAR(36)
  stock_item_int_id INT
```

### Order Folders (junction table)
```
order_folders:
  order_id    CHAR(36) FK
  folder_name VARCHAR(100)
```

### Key Migration Notes

1. **GeneralInfo should be flattened** into the orders table — it's the most accessed sub-entity but only ~6 of its 20 properties are used
2. **TotalsInfo can be flattened** — only 3-4 fields matter
3. **ShippingInfo can be flattened** — only 5 fields matter
4. **Item model is dual-purpose** (order item + stock item) — the new schema should separate these concerns. 50% of Item properties are unused in the order context
5. **Note and ExtendedProperty** are write-only DTOs — they don't need to be stored locally, just created and sent to the Linnworks API
6. **73 of 139 total properties (53%) are unused** — significant opportunity to simplify