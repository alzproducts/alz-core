# Plan: Add RRP to Price Update Write Path

## Context

ShopWired's `POST /products/prices` API supports a `comparePrice` field (RRP / "Was" price), but our codebase only sends `price` and `salePrice`. The RRP already exists on the **read** path (`ProductResponse.comparePrice`, `ProductView.comparePrice`, `Product.comparePrice`) — this change adds it to the **write** path so merchants can update the RRP via the same pricing API.

**Naming boundary:** Our domain uses `rrp` (Recommended Retail Price). ShopWired uses `comparePrice`. Translation happens at the infrastructure boundary:
- **Domain, Application, Presentation:** `rrp`
- **Infrastructure** (ShopWired clients, response DTOs, DB mappers): `comparePrice` / `compare_price`

**Scope:** Master products only — ShopWired does not support `comparePrice` on variations.

**DB persistence:** Already handled. The existing post-update `ProductSyncService` re-fetches the product from ShopWired and `ProductModelMapper` persists `compare_price` to `shopwired.products`. No new migration needed.

**Design decisions:**
- Null semantics match existing pattern: `null` = no change, `Money::inclusive(0)` = clear
- No cross-field validation of RRP vs price/salePrice (user confirmed)
- No VAT round-trip validation for RRP — it's a display-only "Was" price, not transactional
- Additive only — all new params default to `null`, zero breaking changes

---

## Phase 1: Domain Layer

### 1.1 `ProductRetailPricing` — add `rrp` property
**File:** `app/Domain/Catalog/Product/ValueObjects/ProductRetailPricing.php`

- Add `public ?Money $rrp = null` as third constructor param
- Update `forMainProduct()`: add `?float $rrp = null` param, convert with `Money::inclusive()`
- Update `forVariation()`: add `?float $rrp = null` param (defaults null — variations don't have RRP)
- No changes to `saleActive()`, `effectivePrice()`, `taxType()` — RRP is display-only

### 1.2 `UpdatePriceCommand` — add `rrp` property
**File:** `app/Domain/Catalog/Product/Commands/UpdatePriceCommand.php`

- Add `public ?Money $rrp = null` as fourth constructor param (after `$salePrice`)
- Update `hasAnyUpdate()`: add `|| $this->rrp !== null`
- No cross-field validation needed

### 1.3 `ResolvedPriceUpdate` — carry-forward for `rrp`
**File:** `app/Domain/Catalog/Product/ValueObjects/ResolvedPriceUpdate.php`

- In `resolveEffectivePricing()`: resolve RRP with zero-means-clear semantics
- Add `resolveRrp(?Money $commanded, ?Money $current): ?Money` — identical pattern to `resolveSalePrice()`
- Pass resolved RRP to `new ProductRetailPricing(... rrp: $effectiveRrp)`

### 1.4 `PriceChangedValidator` — include `rrp` in change detection
**File:** `app/Domain/Catalog/Product/Validators/PriceChangedValidator.php`

- Add third `NullableMoneyEqualsValidator` comparing `$this->proposed->rrp` vs `$this->current->rrp`
- Include in `$changed` OR chain

### 1.5 `PriceChangedResult` — add `rrp` to context
**File:** `app/Domain/Catalog/Product/Validators/PriceChangedResult.php`

- Add `private float $currentRrpGross = 0.0` constructor param
- Update `reason()` format string: append `, rrp £%s`
- Add `'rrp_gross'` to `context()` array

### 1.6 `ProductRetailPricingTransformer` — map `comparePrice` → `rrp`
**File:** `app/Domain/Catalog/Product/Transformers/ProductRetailPricingTransformer.php`

- Line 28: pass `$product->comparePrice` as the `rrp` param to `forMainProduct()` (translation point: ShopWired's `comparePrice` → domain's `rrp`)
- `forVariation()` call (line 36): no change needed — default `null` is correct

### 1.7 `PriceCommandsVatRoundTripValidator` — NO CHANGES
RRP is a display-only "Was" price, not transactional. A penny discrepancy from VAT round-trip doesn't affect what the customer pays. Skip VAT round-trip validation for RRP.

---

## Phase 2: Infrastructure Layer

### 2.1 `PriceUpdateClient::formatItem()` — map `rrp` → `comparePrice` for API
**File:** `app/Infrastructure/Shopwired/Clients/PriceUpdateClient.php`

- After the salePrice block (line 119-121), add:
  ```php
  if ($command->rrp !== null) {
      $item['comparePrice'] = $command->rrp->toGross();
  }
  ```
  Translation point: domain `rrp` → ShopWired API `comparePrice`

---

## Phase 3: Presentation Layer

### 3.1 `SkuPriceUpdateDTO` — add `rrp` input field
**File:** `app/Presentation/Http/Shopwired/DTOs/SkuPriceUpdateDTO.php`

- Add `#[Min(0)] public readonly ?float $rrp = null`
- Note: the `SnakeCaseMapper` is NOT relevant here — the API field is `rrp` (not snake_cased from anything)
- Update `toCommand()`: pass `rrp: $this->rrp !== null ? Money::inclusive($this->rrp) : null`

---

## Phase 4: Tests

### Existing test files to update:

| Test File | Changes |
|-----------|---------|
| `tests/Unit/.../Commands/UpdatePriceCommandTest.php` | Add cases: rrp-only, all three fields, hasAnyUpdate with rrp |
| `tests/Unit/.../ValueObjects/ProductRetailPricingTest.php` | Add cases: rrp default null, set via constructor, factory methods |
| `tests/Unit/.../ValueObjects/ResolvedPriceUpdateTest.php` | Add cases: carry-forward, override, zero-clears for rrp |
| `tests/Unit/.../Validators/PriceChangedValidatorTest.php` | Add case: rrp-only change detected; update context assertions for `rrp_gross` key |
| `tests/Unit/.../Transformers/ProductRetailPricingTransformerTest.php` | Update `createProduct()` helper; add rrp test case |

### Files NOT requiring changes:
- `HasValidRetailPricingValidator` / `HasValidRetailPricingResult` — no RRP validation
- `PriceCommandsVatRoundTripValidator` — RRP excluded from round-trip (display-only)
- `UpdateProductPricesUseCase` — orchestration unchanged, passes commands through
- `PriceUpdateClientInterface` — contract unchanged (accepts `UpdatePriceCommand[]`)
- Events (`SkuRetailPricingUpdatedEvent`, `ProductPricingUpdatedEvent`) — they carry `ProductRetailPricing` which gains `rrp` automatically

---

## Phase 5: Rename `comparePrice` → `rrp` on ProductView + API response

### 5.1 `ProductView` — rename property
**File:** `app/Domain/Catalog/Product/ValueObjects/ProductView.php`

- Rename property declaration (line 37): `public ?Money $comparePrice` → `public ?Money $rrp`
- Rename assignment (line 133): `$this->comparePrice` → `$this->rrp`
- Update docblock (line 69): keep constructor param name `$comparePrice` (matches SQL view column) but property is `$rrp`

### 5.2 `ProductResource` — rename API key
**File:** `app/Presentation/Http/Api/Resources/ProductResource.php`

- Line 64: `'compare_price' => $product->comparePrice?->toGross()` → `'rrp' => $product->rrp?->toGross()`

### 5.3 Tests — update any ProductView property access
No existing tests reference `->comparePrice` on ProductView (verified via grep).

---

## Phase 6: Force-include variations on product detail + ProductViewMeta

Variations are always loaded on the detail endpoint (no longer optional — breaking change, coordinated with frontend team). `ProductViewMeta` is computed inside `ProductView`'s constructor as a self-contained property.

### 6.1 `ProductViewMeta` — new Domain VO (self-constructing)
**File:** `app/Domain/Catalog/Product/ValueObjects/ProductViewMeta.php` *(new)*

Owns all meta computation. Constructor accepts the raw inputs needed to derive each flag.

```php
final readonly class ProductViewMeta
{
    public bool $canEditRrp;

    /**
     * @param list<ProductVariationView>|null $variations
     */
    public function __construct(?array $variations)
    {
        $this->canEditRrp = self::resolveCanEditRrp($variations);
    }

    /**
     * @param list<ProductVariationView>|null $variations
     */
    private static function resolveCanEditRrp(?array $variations): bool
    {
        return $variations === null
            || $variations === []
            || self::variationsHaveSameSellingPrice($variations);
    }

    /**
     * @param list<ProductVariationView> $variations Non-empty list
     */
    private static function variationsHaveSameSellingPrice(array $variations): bool
    {
        $firstPrice = $variations[0]->price->toGross();

        return array_all(
            $variations,
            static fn(ProductVariationView $v): bool => $v->price->toGross() === $firstPrice,
        );
    }
}
```

- Uses base `price` (not effectivePrice) — RRP is permanent, not tied to sales
- Each future meta flag gets its own `resolve*` method + the raw inputs it needs via the constructor
- If any of these methods are needed elsewhere on ProductView later, they can be extracted out

### 6.2 `ProductView` — add meta property
**File:** `app/Domain/Catalog/Product/ValueObjects/ProductView.php`

- Add `public ProductViewMeta $meta` property
- Constructor: `$this->meta = new ProductViewMeta($variations);` (using constructor param, not `$this->variations`)
- Single line — all computation lives in `ProductViewMeta`

### 6.3 `EloquentProductRepository` — always load variations for detail queries
**File:** `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

- In `findProductForApi()` (line 133-143): always include `'variations'` in the relations array, independent of the includes list
- Simplest: add `'variations'` directly in the `findProductForApi` call before delegating to `relationsForIncludes()`, e.g.:
  ```php
  relations: array_unique(['variations', ...self::relationsForIncludes($query->includes)]),
  ```
- No use case or application layer changes needed — this is an infrastructure concern

### 6.4 `GetProductResult` / `GetProductUseCase` — NO CHANGES
Meta lives on `ProductView` itself. Variations are loaded by the repository. Neither the use case nor the result wrapper need modification.

### 6.5 `ShowProductRequestDTO` — remove `variations` from allowed includes
**File:** `app/Presentation/Http/Api/DTOs/ShowProductRequestDTO.php`

- Override `allowedIncludes()` to exclude `ProductInclude::Variations->value` from the list
- This is a **breaking change** — clients sending `?include=variations` will get a 422. Coordinated with frontend team.

### 6.6 `ProductDetailResource` — always include variations + add meta
**File:** `app/Presentation/Http/Api/Resources/ProductDetailResource.php`

- Remove the conditional `if ($result->hasInclude(ProductInclude::Variations))` check (line 55-57)
- Always include `'variations' => ProductVariationResource::collection($product->variations ?? [])`
- Add `'meta' => ['can_edit_rrp' => $result->product->meta->canEditRrp]` to the response (inside `data`)

### 6.7 Tests

| Test File | Changes |
|-----------|---------|
| `tests/Unit/Domain/.../ValueObjects/ProductViewMetaTest.php` *(new)* | Test via ProductView: no variations → true, all same price → true, different prices → false, single variation → true, null variations → true |
| Existing ProductDetailResource tests (if any) | Update to expect `meta` key and always-present `variations` |

---

## Edge Cases

1. **Variations**: `ProductVariation` has no `comparePrice` field → RRP always `null` in carry-forward → never sent to API for variation SKUs
2. **Zero-means-clear**: `Money::inclusive(0)` sends `comparePrice: 0` to ShopWired (clears RRP); resolves to `null` in effective pricing
3. **Backward compat**: All new params default `null` — no existing call sites break
4. **canEditRrp with sales**: Uses base `price` not `effectivePrice` — a product with same base prices but different sale prices is still editable for RRP
5. **Breaking change**: `?include=variations` on detail endpoint returns 422 — coordinated with frontend
6. **Queue serialization**: `RecordPricePeriodJob` serializes `ProductRetailPricing` to Redis. In-flight jobs from before deployment won't have `rrp` — safe because `RecordPricePeriodUseCase` never accesses it

---

## Verification

1. `make lint` — confirm PHPStan, Arkitect, Deptrac all pass
2. `make test` — confirm all existing + new tests pass
3. Manual: construct an `UpdatePriceCommand` with only `rrp` set → verify `hasAnyUpdate()` returns true, `PriceUpdateClient::formatItem()` outputs `comparePrice` key in payload
4. Manual: hit `GET /products/{id}` without `?include=variations` → verify `variations` and `meta` are both present
5. Manual: hit `GET /products/{id}` for a product with all same-priced variations → verify `meta.can_edit_rrp: true`
6. Manual: hit `GET /products/{id}` for a product with different-priced variations → verify `meta.can_edit_rrp: false`
