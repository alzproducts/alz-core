# Fix `ProductView` Stock & Effective-Price Regressions + Variant-Level Aggregation

## Context

Two related bugs have surfaced on `/api/products` since the View + Assembler migration (PR #596 / #598):

1. **Stock regression.** Products with variations now report `available_stock: 0` in the list response even when variations hold positive stock. The consumer frontend relies on this value for availability gating.
2. **Effective price = £0.00.** Variant-only master products have `price = 0` in `shopwired.products` (pricing lives on variations), so the SQL view's `effective_price` resolves to `0.00` and the API returns `£0.00`.

At the same time, four related **improvements** should ship in the same PR because they exercise the same aggregation machinery introduced by the fixes:

- If every variation shares a `costPrice` → expose it on `ProductView.cost_price`.
- If every variation shares an `effectivePrice` → expose it on `ProductView.effective_price`.
- If both are common → recompute and expose `ProductView.profit_margin`.
- Apply the same master/common/minimum fallback to `ProductView.price` so the non-sale selling price is never £0.00 on variant-only products either.
- Surface a pre-computed `hasSingleSellingPrice` boolean in the API response so the consumer frontend can render "from £X" vs a fixed price without needing the full variations include.

All of this should live in the domain layer, consistent with the existing `Stock::fromParentAndVariants()` / `ProductVariationView::commonDefaultSupplier()` / `ProductView::resolveHighestRrp()` patterns.

## Root-Cause Analysis

### Stock regression

`app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php:469-491` always eager-loads the `variations` relation, regardless of requested API includes. The assembler at `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php:66` resolves them into `$allVariations` and uses the full list for derivations like `hasAnyVariationOnSale` (line 104) and `defaultSupplier` (line 68).

**But at line 95** the assembler gates the `variations` constructor argument behind the public include:

```php
variations: \in_array(ProductInclude::Variations, $includes, true) ? $allVariations : null,
```

`ProductView::__construct()` at line 138-142 then feeds that same (possibly null) list into `Stock::fromParentAndVariants()`. When the caller didn't pass `?include=variations`, `Stock` sees `$variations === null`, falls back to `$parentAvailableStock` / `$parentPhysicalStock` (line 48-50 of `Stock.php`), which are 0 for variant-only masters. Hence `stock: 0` on the list endpoint.

### Effective price £0.00

`database/migrations/2026_04_18_024602_*.php` (products_view CTE, line 48-53) computes:

```sql
effective_price = CASE WHEN sale_price > 0 AND sale_price < price THEN sale_price ELSE price END
```

Variant-only masters store `price = 0` in `shopwired.products` — their real pricing lives on variations. The CTE therefore yields `effective_price = 0.00`, passed unmodified into the VO and exposed by the API.

## Approach

Add a **shadow constructor parameter** `$allVariations` to `ProductView` that carries the fully-loaded variations list independent of the public `$variations` gating. All internal derivations (stock, effective-price fallback, common cost, common effective, aggregated margin) read from `$allVariations`. The public `$variations` property stays gated.

This matches the existing assembler pattern (line 104: `hasAnyVariationOnSale: ProductVariationView::anyOnSale($allVariations)`), and keeps aggregation logic in the domain rather than SQL or the assembler.

### Derivation rules

| Field | Rule |
|---|---|
| `stockLevel` | `Stock::fromParentAndVariants($parentAvailable, $parentPhysical, $allVariations)` — same as today, but now always receives the loaded list. |
| `price` | If master `> 0` → keep master.<br>Else if all variations agree → use common.<br>Else if variations differ → use **minimum** variation `price`.<br>Else (no variations) → master (even if 0 — genuine edge case). |
| `effectivePrice` | Same rule as `price`, applied to `effectivePrice`. Guarantees never £0.00 on variant-only products with variant inventory. |
| `costPrice` | If master non-null → keep master.<br>Else if all variations agree on non-null cost → use common.<br>Else → null. |
| `profitMargin` | If both `costPrice` and `effectivePrice` resolve from the **same source** (both master, or both common-variation) AND both are non-null AND `effective.isZero() === false` → recompute `(effective.toNet() - cost.toNet()) / effective.toNet() * 100`, rounded to 2dp.<br>Else → keep master's precomputed margin (null for variant-only products). The `isZero()` guard is **mandatory** — without it, a variant-only master whose variations all resolve to zero (edge case but legal) would trigger `DivisionByZeroError`. |
| `hasSingleSellingPrice` | Pre-computed boolean from `$allVariations`: `true` when no variations OR all variation prices match the reference (master when master > 0, else `$allVariations[0]->price`). Lifts the current `hasSingleSellingPrice()` method's logic into a constructor-time derivation so it's exposed on the list API without requiring `?include=variations`. |

## Changes

### 1. `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php`

Add static helpers alongside the existing `commonDefaultSupplier()` / `anyOnSale()`:

```php
/** @param list<self> $variations */
public static function commonCostPrice(array $variations): ?Money
public static function commonPrice(array $variations): ?Money
public static function commonEffectivePrice(array $variations): ?Money
public static function minPrice(array $variations): ?Money
public static function minEffectivePrice(array $variations): ?Money
```

- `commonCostPrice`: returns shared Money when every variation has the same non-null `costPrice`, else null. Use `Money::amountEquals()`.
- `commonPrice` / `commonEffectivePrice`: same pattern on the always-non-null Money fields.
- `minPrice` / `minEffectivePrice`: lowest value across variations (compare by `toGross()` for a stable ordering regardless of tax type). Returns null when variations empty.
- **Empty-list handling**: All five helpers treat `$variations === []` the same as "no data" → return `null`. Callers receiving `null` fall through to the master-value branch. This keeps the helpers defensive against a future change where variations could be loaded lazy-empty.

### 2. `app/Domain/Catalog/Product/ValueObjects/ProductView.php`

- Add constructor parameter `?array $allVariations` (list<ProductVariationView>|null), placed immediately after `?array $variations`. **Not** stored as a property — derivation-only.
- Unpromote `profitMargin` (currently `public ?float $profitMargin`) into a separate `public ?float $profitMargin;` property assigned inside the constructor after derivation.
- Add a new public property `public bool $hasSingleSellingPrice;` that the constructor assigns (pre-computed from `$allVariations`). Remove the existing `hasSingleSellingPrice()` method. One production caller must be swapped to property access: `app/Application/Shopwired/PricingUpdate/UseCases/ReconcileShopwiredComparePriceUseCase.php:60` (`$productView->hasSingleSellingPrice()` → `$productView->hasSingleSellingPrice`). Verified via grep — no other production callers.
- Rewrite the bottom of the constructor to derive in this order:
  1. Compute `$this->price` via master/common/min rules.
  2. Compute `$this->effectivePrice` via master/common/min rules.
  3. Compute `$this->costPrice` via master/common rules.
  4. Compute `$this->profitMargin` via recompute-or-keep-master rules, using finalised cost/effective + source tracking.
  5. Compute `$this->stockLevel` via `Stock::fromParentAndVariants(..., $allVariations)`.
  6. Compute `$this->hasSingleSellingPrice` from `$allVariations` + finalised `$this->price`.
- Introduce private static helpers for clarity and testability:
  - `resolvePrice(Money $master, ?array $allVariations, TaxType $taxType): Money`
  - `resolveEffectivePrice(Money $master, ?array $allVariations, TaxType $taxType): Money`
  - `resolveCostPrice(?Money $master, ?array $allVariations): ?Money`
  - `resolveProfitMargin(?float $masterMargin, ?Money $cost, Money $effective, bool $bothFromVariations): ?float`
  - `resolveHasSingleSellingPrice(Money $price, ?array $allVariations): bool`

**Source-tracking for `profitMargin` recompute**: track two local booleans inside the constructor (`$costFromVariations`, `$effectiveFromVariations`) set alongside each resolve call. Pass `$bothFromVariations = $costFromVariations && $effectiveFromVariations` into `resolveProfitMargin`. When either came from master, don't mix sources — return `$masterMargin` unchanged.

`allOnSaleSkus()` keeps its `Assert::notNull($this->variations)` guard — only called from sites that include variations.

### 3. `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php`

At line 95–96, add the new constructor arg:

```php
variations: \in_array(ProductInclude::Variations, $includes, true) ? $allVariations : null,
allVariations: $allVariations,
```

No other assembler changes.

### 3b. `app/Application/Shopwired/PricingUpdate/UseCases/ReconcileShopwiredComparePriceUseCase.php`

Line 60: swap the method call to a property read:

```php
// before
$target = $productView->hasSingleSellingPrice()
// after
$target = $productView->hasSingleSellingPrice
```

Confirm the corresponding test (`tests/Unit/Application/Shopwired/PricingUpdate/UseCases/ReconcileShopwiredComparePriceUseCaseTest.php`) still passes; its ProductView is Mockery-mocked, so update any `->hasSingleSellingPrice()` expectations to `__get('hasSingleSellingPrice')`.

### 4. Tests — unit

`tests/Unit/Domain/Catalog/Product/ValueObjects/ProductVariationViewTest.php`:
- `common_cost_price_returns_shared_money_when_all_agree`
- `common_cost_price_returns_null_when_any_differs`
- `common_cost_price_returns_null_when_any_variation_has_null_cost`
- `common_price_*` (agree / differ)
- `common_effective_price_*` (agree / differ)
- `min_price_picks_lowest`
- `min_effective_price_picks_lowest`

`tests/Unit/Domain/Catalog/Product/ValueObjects/ProductViewTest.php`:
- `stock_level_sums_all_variations_even_when_public_include_not_requested` (core regression test — covers the exact bug)
- `price_uses_master_when_non_zero`
- `price_falls_back_to_common_variation_when_master_zero`
- `price_falls_back_to_minimum_variation_when_master_zero_and_variations_differ`
- `effective_price_uses_master_when_non_zero`
- `effective_price_falls_back_to_common_variation_when_master_zero`
- `effective_price_falls_back_to_minimum_variation_when_master_zero_and_variations_differ`
- `cost_price_uses_master_when_non_null`
- `cost_price_falls_back_to_common_variation_when_master_null`
- `cost_price_is_null_when_variations_differ_on_cost`
- `profit_margin_recomputed_when_cost_and_effective_both_from_common_variants`
- `profit_margin_keeps_master_value_when_master_cost_present`
- `profit_margin_null_when_no_cost_available`
- `has_single_selling_price_true_when_all_variations_match`
- `has_single_selling_price_false_when_variations_differ`
- `has_single_selling_price_true_when_no_variations`
- `has_single_selling_price_uses_first_variation_as_reference_when_master_is_zero`

Existing stock aggregation tests at lines 386–414 and the existing `hasSingleSellingPrice` tests at lines 112–176 need updating: they currently call the method; they should read the property instead. Verify constructor argument ordering in fixtures when inserting the new `$allVariations` param.

Two other test files directly instantiate `new ProductView(` and also need the new `$allVariations` constructor arg added:
- `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php` (3 sites)
- `tests/Unit/Infrastructure/Notifications/Listeners/ProductPricingUpdatedSlackListenerTest.php` (1 site)

Other `tests/**` files that reference `ProductView::class` (e.g. `GetProductCustomFieldsUseCaseTest`, `CheckExpiredSalesUseCaseTest`, `ReconcileProductSaleStateUseCaseTest`, `ProductSaleStateResolverTest`) only use Mockery mocks — they don't instantiate the VO and therefore are not affected by the signature change.

### 5. Tests — feature

`tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php`:
- Reuse the existing helper pattern: the test file mocks `ProductRepositoryInterface` (line 43–44) and returns `ProductView` objects constructed directly via `createProductWithVariations()` (line 985–1043) / `createProduct()` (line 1045–1084). No DB seeding required — extend these helpers to accept a `$masterPrice = 0` / `$variationPrices` parameter.
- Add a regression test: variant-only product (master `price = 0`, `stock = 0`) with two in-stock variations at identical prices, hit `GET /api/products` (no `?include`), and assert the response payload carries `available_stock > 0`, `price > 0`, `effective_price > 0`, and `has_single_selling_price: true`.
- Add a second variant-only scenario with differing variation prices, assert `has_single_selling_price: false` and the aggregate prices equal the minimum variation price.

### 6. Presentation

`app/Presentation/Http/Api/Resources/ProductResource.php::baseFields()`:
- Add a new serialised field `'has_single_selling_price' => $product->hasSingleSellingPrice,` next to the existing `has_any_sale` / `has_free_delivery` fields. Property read — no method call.

## Critical files

- `app/Domain/Catalog/Product/ValueObjects/ProductView.php` — new params + derivation logic + `hasSingleSellingPrice` property (lines 76–143 + method removal at 154–170)
- `app/Domain/Catalog/Product/ValueObjects/ProductVariationView.php` — new static helpers (after line 151)
- `app/Domain/Catalog/Product/ValueObjects/Stock.php` — **unchanged** (already has `fromParentAndVariants`)
- `app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php` — pass `allVariations` (line 95)
- `app/Presentation/Http/Api/Resources/ProductResource.php` — expose `has_single_selling_price` (line 55-93 baseFields block)
- `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductViewTest.php` — new coverage + update existing method-call sites
- `tests/Unit/Domain/Catalog/Product/ValueObjects/ProductVariationViewTest.php` — new coverage
- `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php` — regression tests

## API Backwards Compatibility

- **Changed values** (not new fields — same keys, different data):
  - `price` and `effective_price` — currently `0.00` on variant-only products; will become the aggregated (common or minimum) variation price. Consumers treating `0` as a sentinel for "price not set" will need to treat non-zero as "real price".
  - `cost_price` — currently `null` on variant-only products; may now populate when every variation shares a cost. Consumers that already handled `null | number` need no changes.
  - `profit_margin` — currently `null` when master cost is null; may now populate when both cost and effective aggregate from the same variant-level source.
- **New field**: `has_single_selling_price` (boolean). Pure addition — existing clients will ignore it.

## What NOT to change

- SQL views (`catalog.products_view`, `catalog.product_variations_view`). Aggregation belongs in the domain per the existing `Stock::fromParentAndVariants` / `commonDefaultSupplier` conventions. SQL stays authoritative for master-level pricing.
- `ProductDetailResource.php`. It extends `ProductResource::baseFields()`, so picks up the new field automatically.
- Repository interface or `EloquentProductRepository`. Relations are already eager-loaded correctly.
- `Money` VO. `toNet()` / `amountEquals()` / `isZero()` / `toGross()` are all that's needed.

## Verification

1. `make lint` — Pint, PHPStan, PHPArkitect, Deptrac green. No new baseline entries.
2. `make test` — new unit + feature tests pass; existing `ProductViewTest` stock aggregation tests (lines 386–414) still pass.
3. Manual local verification:
   - `php artisan octane:start --watch`
   - `curl -H "X-Local-Bypass: $API_BYPASS_SECRET" http://127.0.0.1:8000/api/products?per_page=20 > tmp/products.json`
   - Inspect a variant-only product: confirm `available_stock > 0`, `price > 0`, `effective_price > 0`, and `has_single_selling_price` is a boolean.
   - Inspect a product where all variants share cost+effective: confirm `cost_price`, `effective_price`, and recomputed `profit_margin` are populated sensibly; `has_single_selling_price: true`.
4. Hit the detail endpoint with `?include=variations`: confirm parent fields are consistent with the variation list (cost/effective match when all variants agree, `has_single_selling_price` matches the manual comparison).
