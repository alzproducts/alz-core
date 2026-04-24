# ShopWired Custom Fields ↔ Linnworks Extended Properties Sync — Handover Document

**Date:** 2026-04-22
**Scope:** The business logic that ties ShopWired product Custom Field edits to Linnworks StockItem Extended Property updates — which fields are paired, how values are transformed, what triggers the sync, and the known gaps.
**Audit source repo:** `/Users/tom/code/alz-connect` (legacy alz-connect PHP app, branch `master`)

---

## 1. Feature Overview

Within this repo, nothing runs a scheduled job that polls ShopWired for custom-field value changes and writes them to Linnworks. The "sync" is **edit-driven**, triggered from the alz-connect admin CRM UI.

The user-facing flow is:

1. An admin opens a page in alz-connect (e.g. `/products/adv/stock-status.php` or `/products/props/customs.php`).
2. The page renders a **paired form**: for each configured field name, it shows the current **Linnworks EP value** side-by-side with the **ShopWired Custom Field value**.
3. Editing either field fires a Jaxon AJAX call to a single backend entry point — `UpdateOne::updateItemEp()`.
4. `UpdateOne` writes to **Linnworks first** (the EP), then **optionally** writes the same value back to ShopWired (the custom field).

So strictly speaking, the system does not observe ShopWired and mirror changes into Linnworks. It presents a **unified editor** for a small, hardcoded set of fields whose name exists in both systems, and writes to both on save. The Linnworks EP is the canonical write; the ShopWired write is conditional (`setUpdateSw(true)` is the default for the shared form).

**Scope of what's editable.** The default paired-form fields for each page are compile-time lists in `Names::PROPS` (stock-status) and `Names::CUSTOMS_PROPS` (customs). However, `cf-list-get-combined` (`legacy/src/AlzMvc/Core/Container/Other/custom_fields.php:40-45`) lets a POST request override this at runtime via `SelectStockItemCfForm::KEY_PROP_NAME`, meaning **any** SW CF / LW EP pair can be driven through this framework if submitted that way. For the new system, treat the two compile-time lists as the primary contract and the POST override as a power-user escape hatch.

**Directionality summary:**

| Trigger | LW EP written | SW CF written |
|---------|---------------|---------------|
| Admin edits a paired field in alz-connect | ✅ always | ✅ when `updateSw=true` (form default) |
| ShopWired custom field edited directly in SW admin | ❌ no | — |
| Cron / scheduled job in this repo | ❌ no (for custom fields) | — |

The separate daily cron `html/cron/daily/shopwired_linnworks_sync.php` → `ShopwiredLinnworksSyncCronController::actionCron()` only calls `SyncSellingPriceToLinnworksService::syncPrices()` — it syncs `selling price`, `sw_url` and `MetaDescription` only; it does **not** touch custom fields (verified at `legacy/src/Mvc/Controller/Cron/Linnworks/ShopwiredLinnworksSyncCronController.php:22-28`).

> **Caveat on "no sync":** this audit covers alz-connect source only. If ShopWired is configured (on its side) to fire a webhook to an alz-connect endpoint on custom-field change, that would sit outside this repo. No receiver handler for such a webhook was found.

---

## 2. Architecture Diagram

```
Admin CRM page (html/products/adv/stock-status.php, html/products/props/customs.php)
                                 │
                                 ▼
            ┌────────────────────────────────────────────┐
            │ UpdateUniquePropService::pageActions()     │
            │ legacy/src/Alz/Pages/UniqueProps/          │
            │   UpdateUniquePropService.php              │
            └───────────────────────┬────────────────────┘
                                    │ per stock item
                                    ▼
            ┌────────────────────────────────────────────┐
            │ MultiCustomFieldAjaxForm                   │
            │   ::createAllFormsByPkIds()                │
            │ legacy/src/Form/Ajax/Products/             │
            │   MultiCustomFieldAjaxForm.php             │
            └───────────────────────┬────────────────────┘
                                    ▼
            ┌────────────────────────────────────────────┐
            │ CustomFieldsGroup                          │
            │   - hydrateLwPropCollection()              │
            │   - hydrateSwPropCollection()              │
            │   - hydratePairCollection()                │  pairs LW EP ↔ SW CF by NAME MATCH
            │ legacy/src/Form/Leg/CustomFieldsGroup.php  │
            └───────────────────────┬────────────────────┘
                                    ▼
            ┌────────────────────────────────────────────┐
            │ UniPropLinnShopForm (Jaxon inputs)         │
            │ setUpdateSw(true) — default                │
            │ legacy/src/Alz/PageForm/CustomFields/Form/ │
            │   UniPropLinnShopForm.php                  │
            └───────────────────────┬────────────────────┘
                                    │ user edits one input
                                    ▼
        ═════════════ Jaxon AJAX call ═════════════
        registered in: legacy/src/AlzMvc/Core/Container/Other/jaxon.php:72-76
        callable name: "updateItemEp" → UpdateOne::updateItemEp
                                    │
                                    ▼
            ┌────────────────────────────────────────────┐
            │ UpdateOne::updateItemEp(                   │
            │   pkStockId, epName, epValue,              │
            │   updateSw=true, shopId)                   │
            │ legacy/src/Api/AlzApi/UniqueProp/Update/   │
            │   UpdateOne.php:111                        │
            └───────────────────────┬────────────────────┘
                                    │
              ┌─────────────────────┴────────────────────┐
              ▼                                          ▼
   ┌─────────────────────┐                ┌───────────────────────────────┐
   │ updateLwEp()        │                │ updateSwCf() (if updateSw)     │
   │ UpdateOne.php:170   │                │ UpdateOne.php:205              │
   │                     │                │                                │
   │ calcSetLwValue()    │                │ UpdateSwCf::update(            │
   │  └─ empty+Names::   │                │   productId, [epName=>epValue])│
   │     PROPS → "0"     │                │ UpdateSwCf.php:71              │
   │                     │                │                                │
   │ lw2->alzInv()       │                │   ├─ getLiveEps()              │
   │   ->update()        │                │   │   (GET from SW)            │
   │   ->oneEp(…)        │                │   ├─ mergeFormatKeyValsWithLive│
   │                     │                │   │   (+ convertForShopUpdate) │
   │ → Linnworks API     │                │   └─ productCustomFieldsUpdate │
   └─────────────────────┘                │       (PUT to SW)              │
                                          └───────────────────────────────┘
```

---

## 3. Entry Points (How the flow starts)

### 3.1 Admin pages (primary trigger)

| URL / file | Field list it edits | Controller |
|------------|---------------------|------------|
| `/products/adv/stock-status.php` | `Names::PROPS` (7 stock-status fields) | `Mvc\Controller\StockStatus\StockStatusMainController` |
| `/products/props/customs.php` | `Names::CUSTOMS_PROPS` (3 customs fields) | `Mvc\Controller\StockStatus\CustomsPropsMainController` |

The URI-to-list mapping is wired in `legacy/src/AlzMvc/Core/Container/Other/custom_fields.php:23-39` under `cf-lists` and `cf-by-uri`, which select the right `Names` constant based on the current request URI.

Both pages ultimately call `UpdateUniquePropService::pageActions()` (`legacy/src/Alz/Pages/UniqueProps/UpdateUniquePropService.php:97`), which is the entry to the paired-form rendering pipeline.

### 3.2 Jaxon AJAX endpoint (the "save" trigger)

The Jaxon request handler is registered once in the DI container:

- **File:** `legacy/src/AlzMvc/Core/Container/Other/jaxon.php:72-76`
- **Public callable name:** `updateItemEp`
- **Bound to:** `AlzApi\UniqueProp\Update\UpdateOne::updateItemEp`

Every edited field in the form generates a small JS snippet (see `MatchedPairFieldAjax::generateJaxString()`, `legacy/src/Alz/PageForm/CustomFields/Fields/MatchedPairFieldAjax.php:61`) that POSTs to `updateItemEp` with `(pkStockId, epName, epValue)`. The shop id is injected by the form; `updateSw` is `true` by default via `UniPropLinnShopForm::__construct()` (`legacy/src/Alz/PageForm/CustomFields/Form/UniPropLinnShopForm.php:24`).

### 3.3 What is NOT an entry point

- **`html/cron/daily/shopwired_linnworks_sync.php`** (→ `ShopwiredLinnworksSyncCronController`) — only handles selling price / URL / meta description. Does **not** touch custom fields.
- **`html/cron/daily/db_products.php`** (→ `Alz\ProductsA::UpdateCustomFields()` at `legacy/src/Alz/ProductsA.php:2371-2437`) — refreshes the **local** MySQL mirror of SW custom-field definitions and values (`shopwired.custom_fields`, `shopwired.custom_field_values`, `shopwired.productsCustomFields`). It never writes to Linnworks.
- **No event listener, webhook handler, or scheduled job was found in this repo** that reads SW CFs and pushes to Linnworks EPs.

---

## 4. The Mapping Layer (single source of truth)

**File:** `legacy/src/AlzMvc/Service/Shopwired/Product/CustomFields/Names.php`

The file is short and is the canonical mapping. There is **no database-driven mapping table, no admin UI to configure new mappings, and no YAML/XML config**. Adding a new mapped field requires a code change here.

### 4.1 The mapping rule

> **The ShopWired custom-field name must equal the Linnworks extended-property name, character-for-character.**

Pairing in `CustomFieldsGroup::hydratePairCollection()` (`legacy/src/Form/Leg/CustomFieldsGroup.php:207-215`) works by calling `AbstractPairedCollection::pairCollections()`, which matches a `LinnPropCollection` entry to a `ShopPropCollection` entry by name only. There is no translation/alias layer.

### 4.2 The two field sets

```php
// Names.php:39-49
public const PROPS = [
    'preorder_date',
    'preorder_disable',
    'preorder_hide',
    'stock_notes',
    'discontinued',
    'other_stock_status',
    'stock_status_internal',
    // 'alt_product'  ← defined but commented out of the array
];

// Names.php:52
public const CUSTOMS_PROPS = [
    'country_of_origin',
    'hs_tariff_code',
    'type_description',
];
```

`Names::ALT_PRODUCT = 'alt_product'` is defined as a class constant but **deliberately excluded** from `PROPS`. Treat it as legacy — there is no live sync for it.

`Names::PROPS` and `Names::CUSTOMS_PROPS` are referenced in only four places in the codebase: `UpdateOne.php:165`, `LinnOneProp.php:17`, and `custom_fields.php:38-39`. The two lists are therefore the full, authoritative paired-field contract.

### 4.3 Prop list to URI binding

`legacy/src/AlzMvc/Core/Container/Other/custom_fields.php:23-39`:

```php
'cf-lists' => [
    '/products/props/customs.php'    => Names::CUSTOMS_PROPS,
    '/products/adv/stock-status.php' => Names::PROPS,
]
```

The list for the current request is selected at DI-bind time via `cf-by-uri`, injected into `MultiCustomFieldAjaxForm` and `CustomsPropsMainController`.

### 4.4 Runtime override: "edit arbitrary fields"

`UpdateUniquePropService::createCombinedPropNames()` (line 138) allows POST data to override the compile-time list via `SelectStockItemCfForm::KEY_PROP_NAME`. This means the power-user form (`SelectStockItemCfForm`, `legacy/src/Alz/Pages/UniqueProps/SelectStockItemCfForm.php`) can ask the paired-form to render **any** EP/CF pair by name — not just those in `PROPS` / `CUSTOMS_PROPS`.

Consequence for the new system: *the page URI is the default filter, but a user can widen it at runtime by submitting `pkStockItemId[]` + a chosen prop list.*

---

## 5. Value Transformations (business rules)

All transforms live in the `UniqueProp` package. There are only two non-trivial rules: **empty↔"0" swap** and **date↔timestamp conversion**.

### 5.1 Rule A — Empty ↔ "0" for stock-status fields

Symmetric rule applied in both directions to any field whose name is in `Names::PROPS`.

**On write to Linnworks** — `UpdateOne::updateEpSwapVals()` (`UpdateOne.php:149-159`):

```
if (epName ∈ Names::PROPS) AND (epValue is empty)
    → write "0" to Linnworks
```

**On write to ShopWired** — `LinnOneProp::convertToShopVal()` (`LinnOneProp.php:56-66`):

```
if (propName ∈ Names::ZERO_AS_EMPTY) AND (propVal === "0")
    → write "" to ShopWired
```

Where `ZERO_AS_EMPTY = Names::PROPS` (`LinnOneProp.php:17`).

**Why it exists (inferred):** Linnworks EPs need a stored value — omitting the value would leave a stale EP — so "unset" is represented as the literal string `"0"`. ShopWired custom fields, however, display `"0"` as a value; the boolean/toggle-style fields (`preorder_disable`, `preorder_hide`, `discontinued`) and text fields (`stock_notes`, `other_stock_status`, `stock_status_internal`, `preorder_date`) need to appear empty in SW when they are cleared. The swap keeps both systems' semantics of "empty" consistent from the user's point of view.

**Scope:** Rule A applies only to `Names::PROPS` (stock-status). It does **not** apply to `Names::CUSTOMS_PROPS` — customs fields are plain strings with no special empty handling.

### 5.2 Rule B — Date string / DateTime → Unix timestamp (SW only)

Only applied on the **SW write path** in `UpdateSwCf::convertForShopUpdate()` (`UpdateSwCf.php:47-63`):

```
1. Run Rule A first (LinnOneProp::convertToShopVal).
2. If resulting value is empty → return as-is.
3. If value is a DateTime instance → return $val->getTimestamp().
4. If the prop name contains the substring "date" (strpos) → run
   ShopOneProp::convertDateToTimeStamp((string)$val).
5. Otherwise → return unchanged.
```

The `strpos($propName, 'date') !== false` check is deliberately loose — it matches `preorder_date` but would also match any future field containing "date" in its name. Keep this in mind when adding new fields.

Linnworks stores dates as text (e.g. `"2026-06-15"`); ShopWired's custom-field API expects a Unix timestamp for date-type fields. Rule B is the adapter.

### 5.3 No other transformations

The audit found **no** HTML stripping, trimming, case normalisation, whitespace collapse, character-set re-encoding, or bool↔string coercion in this code path. Values pass through opaquely unless Rule A or Rule B fires.

---

## 6. Code Inventory (quick-reference map)

### 6.1 The ~10 files you need to read to understand this feature

| File | Role |
|------|------|
| `legacy/src/AlzMvc/Service/Shopwired/Product/CustomFields/Names.php` | Mapping / field lists (lines 13-52) |
| `legacy/src/AlzMvc/Core/Container/Other/custom_fields.php` | DI wiring, URI→list binding (lines 22-81) |
| `legacy/src/AlzMvc/Core/Container/Other/jaxon.php` | Registers `updateItemEp` callable (lines 72-76) |
| `legacy/src/Mvc/Controller/StockStatus/StockStatusMainController.php` | Page controller for `/products/adv/stock-status.php` |
| `legacy/src/Mvc/Controller/StockStatus/CustomsPropsMainController.php` | Page controller for `/products/props/customs.php` |
| `legacy/src/Alz/Pages/UniqueProps/UpdateUniquePropService.php` | Orchestrates per-page form rendering |
| `legacy/src/Form/Ajax/Products/MultiCustomFieldAjaxForm.php` | Loops stock items, builds one `CustomFieldsGroup` each |
| `legacy/src/Form/Leg/CustomFieldsGroup.php` | Pairs LW EPs ↔ SW CFs by name (lines 185-215) |
| `legacy/src/Alz/PageForm/CustomFields/Form/UniPropLinnShopForm.php` | The paired-editor form (defaults `updateSw=true`) |
| `legacy/src/Alz/PageForm/CustomFields/Fields/MatchedPairFieldAjax.php` | Generates the Jaxon JS that fires `updateItemEp` |
| `legacy/src/Api/AlzApi/UniqueProp/Update/UpdateOne.php` | **The save endpoint** — writes LW then SW |
| `legacy/src/Api/AlzApi/UniqueProp/Update/UpdateSwCf.php` | SW-side write (+ value transform) |
| `legacy/src/Api/AlzApi/UniqueProp/Linn/LinnOneProp.php` | Rule A implementation |

### 6.2 Key method signatures

```php
// ENTRY (Jaxon AJAX)
UpdateOne::updateItemEp(
    string $pkStockId,    // Linnworks SKU / stock-item id
    string $epName,       // Paired name (must match in both systems)
    string $epValue,      // New value, raw string
    bool   $updateSw  = false,   // UniPropLinnShopForm passes true
    int|string|null $shopId = null
): bool|Response

// LW WRITE
UpdateOne::updateLwEp()          // private → $lw2->alzInv()->update()->oneEp(...)

// SW WRITE
UpdateSwCf::update(int $productId, array $keyVals): bool
  → mergeFormatKeyValsWithLive()   // applies Rule A + Rule B per field
  → updateOnSw()
  → productCustomFieldsUpdate()    // PUT to SW custom-fields endpoint

// TRANSFORMS
UpdateOne::updateEpSwapVals(string $epName, string $epValue): string  // Rule A (→ LW)
LinnOneProp::convertToShopVal($propVal, string $propName)              // Rule A (→ SW)
UpdateSwCf::convertForShopUpdate($propVal, string $propName)           // Rule A + Rule B (→ SW)
```

---

## 7. Data Structures

### 7.1 ShopWired side

- **API model:** `ShopWired\Model\CustomField\OneField\CustomField` — has `id`, `name`, `type`, `label`, `itemType`, `sortOrder`, `allowedValues`.
- **Filtered collection:** `CustomFieldCollectByName` — built from the `cf-list-get-combined` array via DI (`custom_fields.php:64-66`).
- **Local DB mirror** (populated daily by `ProductsA::UpdateCustomFields()`):
  - `shopwired.custom_fields` — definitions (name, type, label, itemType)
  - `shopwired.custom_field_values` — allowed values for select/dropdown types
  - `shopwired.productsCustomFields` — per-product CF values (fkId, fkName, value)

### 7.2 Linnworks side

- **API model:** `Linnworks\Inventory\ExtendedProperty\Item\ItemExtendedProperty`.
- **Serialised field names** (`AbstractItemExtendedProperty.php:extract(), lines 54-63`): `pkRowId`, `fkStockItemId`, `ProperyName`, `PropertyValue`, `PropertyType`. Note the asymmetric spelling: the **Name** field is `ProperyName` (typo), while **Value** and **Type** are spelled correctly. This asymmetry is load-bearing because the Linnworks API expects those exact keys — preserve it on the way out.
- **Getter names** reflect the same asymmetry: `getProperyName(): string` (typo, `AbstractItemExtendedProperty.php:89`), `getPropertyValue(): string` (correct, line 101), `getPropertyType(): string` (correct, line 113).
- **Collection of EPs for an item:** exposed via `StockItem::getEPs()`.
- **Cached set of all unique EP names:** `InventoryEpCollection` — retrieved via `get-unique-stock-item-eps` DI factory (`custom_fields.php:68-80`), md5-keyed Symfony cache entry.

### 7.3 Product-link lookup

`AlzApi\LinkedItem\Product\ProductLinkLw` resolves the SW product ↔ LW stock item pair. It relies on the Linnworks EP **`sw_id`** (set via `GetShopIdEpService::EP_SHOP_ID`) to find the matching ShopWired product. If `sw_id` is missing at form-render time, `CustomFieldsGroup::createFieldGroup()` (line 230) falls back to `FindBySku::getProduct($lwItem->getItemNumber())`. If that also fails, `throwCannotFindSwProductException()` is thrown and the form for that product is skipped. Note the fallback only runs during rendering — at save time, `shopId` is whatever the form already decided.

---

## 8. Configuration

### 8.1 Where fields are declared

- **Add/remove a paired field:** edit the `PROPS` or `CUSTOMS_PROPS` array in `legacy/src/AlzMvc/Service/Shopwired/Product/CustomFields/Names.php`.
- **Add a new page with its own field list:** add a URI → list mapping in `legacy/src/AlzMvc/Core/Container/Other/custom_fields.php:23-31`.
- **Pre-conditions for adding a field:**
  1. A ShopWired custom field with exactly that name must exist (and its `itemType` must include products).
  2. If the name is in `PROPS`, any empty value will be stored as `"0"` in Linnworks — ensure that is what you want.
  3. If the name contains the substring `"date"`, Rule B will try to convert the value to a Unix timestamp on SW write — ensure the field is a date-type in SW.

### 8.2 Credentials & secrets (reference only — not stored here)

| System | Where accessed | Notes |
|--------|----------------|-------|
| ShopWired REST API | `ShopWired\ShopWiredA` (injected via DI) | Token-based auth, configured in env + DI container (`sw_autowire.php`) |
| Linnworks API | `AlzApi\Handler\LwApi` → `AlzInventory` | OAuth-refresh flow managed by `LwApi`; config in env + `lw_autowire.php` |

No secrets are referenced in any file in this flow — they are injected via the DI container. Do not hardcode keys into the new system.

### 8.3 Caching touch-points

- `get-unique-stock-item-eps` — md5-keyed `CacheInterface` entry in `custom_fields.php:68-80`. Holds the full list of EP names in the Linnworks inventory. **Not invalidated** on EP writes — relies on natural TTL. If you add a brand-new EP name that has never been used before, it may not appear in the paired form until the cache expires.
- `UpdateEntityCustomFields` (`legacy/src/AlzMvc/Service/Shopwired/Http/UpdateEntityCustomFields.php:45-47`) — a different code path (not the live one for this feature) does delete its CF cache after a successful update. The **live** path via `UpdateSwCf` / `ShopWiredA::UpdateProduct` follows whatever invalidation policy is in `ShopWiredA::UpdateProduct('customFields', …, cacheKey='custom_fields', cacheId='customFields')` — see `UpdateSwCf.php:113-127`.

---

## 9. Business Rules Summary (copy-ready for re-implementation)

1. **Pairing = exact name match.** Given a pre-defined list of field names (`Names::PROPS` for stock-status, `Names::CUSTOMS_PROPS` for customs), each name is expected to exist as both a SW custom field (itemType products) and a LW extended property. Pairing is 1:1 on name.
2. **Edits always go to LW first.** `UpdateOne::updateValue()` calls `updateLwEp()` before any SW write. If LW throws, the exception is caught at the outer `updateItemEp()` level and SW is not written.
3. **SW write is conditional.** Only happens if both `updateSw === true` **and** `swProductId` is non-empty. The shared admin form (`UniPropLinnShopForm`) hardcodes `updateSw = true`.
4. **Empty stock-status values become `"0"` in Linnworks.** For any field in `Names::PROPS`, `empty($value)` is persisted as literal string `"0"`. Converse applied on SW write: literal `"0"` is sent as `""`.
5. **Date-named fields are timestamp-converted on SW write.** Any field whose name contains the substring `"date"` is pushed through `ShopOneProp::convertDateToTimeStamp()` before hitting SW. `DateTime` instances use `->getTimestamp()`.
6. **Customs fields (`country_of_origin`, `hs_tariff_code`, `type_description`) are passed through untouched.** No Rule A, no Rule B, no trimming.
7. **Product match uses `sw_id` first, SKU fallback (at render time).** `ProductLinkLw` finds the SW product by the LW `sw_id` EP; `CustomFieldsGroup::createFieldGroup()` falls back to `FindBySku::getProduct($itemNumber)` if `sw_id` is missing. If both fail, the form for that product is not rendered.
8. **Success reporting branches on `updateSw`.** In the live UI flow (`updateSw = true`), `wasSuccess` is the boolean return of `UpdateSwCf::update()` — i.e. success = "the SW write returned a diff-clean result" (`UpdateSwCf::customFieldsUpdateCheckResultsArr()`). The LW write succeeding but the SW write failing will surface to the user as a **failed** badge. In the non-UI flow (`updateSw = false`), success is instead determined by re-reading the LW EP via `hasMatchingEpVal()` and comparing to the value just written. A UI validation badge (`addValidationBadge()`) always reflects the final `wasSuccess`.
9. **Bulk render is capped at 10 stock items per request.** `MultiCustomFieldAjaxForm::$limit = 10` (`MultiCustomFieldAjaxForm.php:104`). When the POSTed `pkStockItemId[]` list exceeds 10, the first 10 are rendered and `Alert::warning` is shown (`alertUpdateMaxLimit`, line 148-151). This does **not** limit how many fields per product render — it caps how many stock items per page. A re-implementation of bulk editing will need its own pagination/limit strategy.

---

## 10. Known Issues & Tech Debt

| # | Issue | File / Line | Impact |
|---|-------|-------------|--------|
| 1 | No automated "SW CF changed → LW EP" sync within this repo. If a user edits a custom field in the ShopWired admin UI directly, Linnworks stays stale until someone opens the alz-connect paired form. | N/A (missing feature) | Divergence risk |
| 2 | Rule B uses loose `strpos($name, 'date')` — accidentally matches any future field name containing "date" (e.g. `update_date_comment`). | `UpdateSwCf.php:58` | Future foot-gun |
| 3 | `Names::ALT_PRODUCT = 'alt_product'` is defined but commented out of `PROPS`. Dead code or pending feature? | `Names.php:48` | Confusion; remove or activate |
| 4 | Success detection re-reads the LW EP (`hasMatchingEpVal()`) — extra round-trip on every save. | `UpdateOne.php:182-203` | Latency |
| 5 | Linnworks API has a hardcoded typo (`ProperyName`) on the Name field only — `PropertyValue` and `PropertyType` are correctly spelled. The `AbstractItemExtendedProperty` model mirrors this asymmetry in both serialisation and getter names. Do not "fix" without coordinating with the Linnworks API side; the asymmetry is load-bearing. | `AbstractItemExtendedProperty.php:89, 101, 113` | Cosmetic; beware when re-implementing |
| 6 | `get-unique-stock-item-eps` cache is never invalidated on EP writes — new EP names will be temporarily invisible to the paired form. | `custom_fields.php:68-80` | Minor UX bug |
| 7 | No unit tests cover this flow. The `tests/` directory has no `*CustomField*`, `*UniqueProp*`, or `*UpdateOne*` coverage. | N/A | Regressions will be caught by humans only |

### 10.1 Low-priority corner case

`UpdateOne::wasSuccess` stays `false` when `updateSw=true` but `swProductId` is null/empty, even if the Linnworks write succeeded (`UpdateOne.php:225-231`). In practice this path is unreachable from the live UI because `CustomFieldsGroup` throws `CannotFindSwProductException` earlier and skips rendering for any stock item without a resolved SW product — so the form never fires `updateItemEp` with a missing `shopId`. Worth knowing if you programmatically call `UpdateOne::updateItemEp` from a new caller.

---

## 11. Re-implementation Checklist (for the new system)

If the new system already has Linnworks EP updates and ShopWired custom field updates working independently, the missing piece is the **coupling rules** above. Port the following, in this order:

- [ ] Two named lists equivalent to `Names::PROPS` (stock-status) and `Names::CUSTOMS_PROPS` (customs). Keep them as code constants unless a non-dev needs to edit them.
- [ ] Rule A: empty ↔ `"0"` swap for `PROPS` only, applied symmetrically on both write paths.
- [ ] Rule B: date-name → timestamp conversion on SW write only. **Prefer matching on an explicit field type flag rather than `strpos($name, 'date')`** to close gap #2.
- [ ] A single save handler that writes LW first, then SW, with a short-circuit if LW fails and an explicit failure when SW product cannot be linked.
- [ ] Product linking: try `sw_id` EP first, SKU as fallback.
- [ ] A paired-form renderer that groups fields by page (stock-status vs customs), selects the list by route, and allows a `pkStockItemId[]` + `propName[]` override for bulk edits.
- [ ] (Nice-to-have) A cron or webhook that closes gap #1 by listening for SW custom-field changes (ShopWired webhooks or a periodic diff of SW CF values vs LW EP values).
- [ ] Unit tests for Rule A and Rule B against every field in `PROPS` and `CUSTOMS_PROPS`, including edge cases (`""`, `"0"`, `0`, `null`, `DateTime`, non-date string in a `*_date` field).

---

**Audit source snapshot:** branch `master` @ 2026-04-22, alz-connect repo.
**Key non-obvious decisions captured:**
- SW↔LW sync is UI-driven, not scheduled.
- Field pairing is by exact name equality; there is no translation table.
- Empty values are stored in Linnworks as `"0"` for the seven `Names::PROPS` fields only; this is load-bearing behaviour, not a bug.
- The Linnworks EP API field-name typo (`Propery*`) is asymmetric and must be preserved on the way out.
