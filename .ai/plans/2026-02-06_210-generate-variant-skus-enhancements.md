# Plan: GenerateVariantSkus Enhancements

Five features + one small infrastructure improvement.

---

## Phase 0: StockItemModel Eloquent Relationship (Bonus)

### 0A. Add `suppliers()` relationship to StockItemModel
**File:** `app/Infrastructure/Linnworks/Models/StockItemModel.php`

Add `HasMany` relationship (mirroring existing `extendedProperties()`):
```php
public function suppliers(): HasMany
{
    return $this->hasMany(StockItemSupplierModel::class, 'stock_item_id', 'stock_item_id');
}
```

Add convenience method:
```php
public function defaultSupplier(): ?StockItemSupplierModel
```
Returns `$this->suppliers->first(fn($s) => $s->is_default)` or null. Returns null if relation not loaded.

### 0B. Map suppliers in StockItemModelMapper
**File:** `app/Infrastructure/Linnworks/Mappers/StockItemModelMapper.php`

Currently `fromModel()` doesn't pass `suppliers:` to `StockItemFull` constructor (defaults to `[]`). Add supplier mapping mirroring the `extendedProperties` pattern:
```php
suppliers: $model->relationLoaded('suppliers')
    ? array_values(array_map(fn(StockItemSupplierModel $s) => $s->toDomain(), $model->suppliers->all()))
    : [],
```

---

## Phase 1: `--copy-mpn` Flag

### 1A. Update Application Command
**File:** `app/Application/Inventory/Commands/GenerateVariantSkusCommand.php`

Add parameter: `public bool $copyParentMpn = false`

### 1B. Update CLI Command
**File:** `app/Presentation/Console/Commands/GenerateVariantSkusCommand.php`

- Add `{--copy-mpn : ...}` to `$signature`
- Pass `copyParentMpn: (bool) $this->option('copy-mpn')` to `UseCaseCommand`
- Display flag status in output header

### 1C. Update Use Case
**File:** `app/Application/Inventory/UseCases/GenerateVariantSkusUseCase.php`

Thread `$command` through `processVariation()` and `buildCreateParams()` method signatures.

In `buildCreateParams()`, change line 255 from:
```php
mpn: $variation->mpn,
```
to:
```php
mpn: $command->copyParentMpn ? $supplier->code : $variation->mpn,
```

`$supplier` is already available (line 222: `$template->getDefaultSupplier()`).

---

## Phase 1.5a: Always Clear Cost Price on ShopWired Update

### Update `UpdateBasicProductCommand`
**File:** `app/Domain/Catalog/Product/Commands/UpdateBasicProductCommand.php`

Add `public bool $clearCostPrice = false`. Update `hasAnyUpdate()` to include it.

### Update `BasicProductUpdateClient::buildPayload()`
**File:** `app/Infrastructure/Shopwired/Clients/BasicProductUpdateClient.php`

Add: `if ($command->clearCostPrice) { $payload['costPrice'] = ''; }`

### Update `GenerateStockItemFromVariationService::generate()`
**File:** `app/Application/Inventory/Services/GenerateStockItemFromVariationService.php`

Always set `clearCostPrice: true` in the `UpdateBasicProductCommand` when writing SKU back to ShopWired.

---

## Phase 1.5b: `--no-supplier` Flag

### Update Application Command
**File:** `app/Application/Inventory/Commands/GenerateVariantSkusCommand.php`

Add parameter: `public bool $noSupplier = false`

### Update CLI Command
**File:** `app/Presentation/Console/Commands/GenerateVariantSkusCommand.php`

Add `{--no-supplier : ...}` to `$signature`, pass to command.

### Make `supplierId` nullable in CreateStockItemParams
**File:** `app/Application/Inventory/Params/CreateStockItemParams.php`

Change `public Guid $supplierId` → `public ?Guid $supplierId = null`

### Conditional supplier linking in LinnworksStockItemCreatorService
**File:** `app/Application/Inventory/Services/LinnworksStockItemCreatorService.php`

In `completeItemSetup()`, wrap supplier creation in `if ($params->supplierId !== null)`.

### Update Use Case
**File:** `app/Application/Inventory/UseCases/GenerateVariantSkusUseCase.php`

- In `validateTemplate()`: skip supplier check when `$command->noSupplier`
- In `buildCreateParams()`: set `supplierId: null`, `supplierCode: null`, `purchasePrice: null` when `$command->noSupplier`. Still use template for `categoryId`.

---

## Phase 2: `--is-standard-sign` Flag

### 2A. New Domain Resolver
**File (new):** `app/Domain/Catalog/Product/Resolvers/StandardSignPriceResolver.php`

Pure domain service. Matches a variation's options against reference variations to find cost price.

```php
final readonly class StandardSignPriceResolver
{
    public function resolve(ProductVariation $variation, array $referenceVariations): ?float
```

**Matching strategy:** Build a normalized key from `option_name + value_name` pairs (case-insensitive, sorted). Compare keys between target and reference variations. Return matched variation's `costPrice` or `null`.

Uses `ProductVariationOption::$optionName` and `$valueName` properties.

### 2B. Config value
**File:** `config/shopwired.php`

Add: `'standard_sign_product_id' => env('SHOPWIRED_STANDARD_SIGN_PRODUCT_ID')`

### 2C. Update Application Command
**File:** `app/Application/Inventory/Commands/GenerateVariantSkusCommand.php`

Add parameter: `public bool $isStandardSign = false`

### 2D. Update CLI Command
**File:** `app/Presentation/Console/Commands/GenerateVariantSkusCommand.php`

- Add `{--is-standard-sign : ...}` to `$signature`
- Pass flag to `UseCaseCommand`

### 2E. Update Use Case
**File:** `app/Application/Inventory/UseCases/GenerateVariantSkusUseCase.php`

New constructor deps:
- `StandardSignPriceResolver $standardSignPriceResolver`
- `ProductRepositoryInterface $productRepository` (already bound at `App\Application\Contracts\Shopwired\ProductRepositoryInterface` in `ShopwiredServiceProvider`)

In `execute()`, after fetching product but before the loop:
```php
$standardSignVariations = $command->isStandardSign
    ? $this->loadStandardSignVariations()
    : null;
```

New private method `loadStandardSignVariations(): array` reads config value, fetches product from local DB via `$this->productRepository->getProduct(IntId::from(...))`, returns its variations.

In `buildCreateParams()`, modify purchase price logic:
```php
// Standard sign match takes priority
if ($standardSignVariations !== null) {
    $matchedCost = $this->standardSignPriceResolver->resolve($variation, $standardSignVariations);
    if ($matchedCost !== null) {
        $purchasePrice = Money::exclusive($matchedCost);
    }
}
// Fall back to normal cost price if no match
if ($purchasePrice === null && $prices->costPrice !== null) {
    $purchasePrice = Money::exclusive($prices->costPrice);
}
```

Thread `?array $standardSignVariations` through `processVariation()` and `buildCreateParams()`.

---

## Phase 3: Slack Notification

### 3A. Add `productTitle` to Result
**File:** `app/Application/Inventory/Results/GenerateVariantSkusResult.php`

Add `public string $productTitle = ''` to constructor. Update `noVariations()` and `allSkipped()` factory methods to accept title. Pass `$product->title` from the use case in all return paths (product is always available before any return).

### 3B. Domain Event
**File (new):** `app/Domain/Inventory/Events/VariantSkusGeneratedEvent.php`

Readonly class with primitive data:
- `int $productId`, `string $productTitle`
- `int $created`, `int $skipped`, `int $failed`
- `list<string> $createdSkus`

### 3C. Slack Notification
**File (new):** `app/Infrastructure/Notifications/Slack/VariantSkusGeneratedNotification.php`

Block Kit format:
- Header: "Variant SKUs Generated"
- Section: Product title + ID
- Section: Created / Skipped / Failed counts
- Divider + Section: SKU list (max 5 shown, `+ X more` for remainder)
- Context: Timestamp

`MAX_SKUS_SHOWN = 5` constant.

### 3D. Queued Listener
**File (new):** `app/Infrastructure/Notifications/Listeners/VariantSkusGeneratedSlackListener.php`

Follows `ContactFormProcessedSlackListener` pattern exactly:
- `implements ShouldQueue`, 3 tries, 60s backoff
- Reads `config('services.slack.notifications.channel')` (default channel)
- `failed()` method logs error

### 3E. Event Registration
**File (new):** `app/Providers/InventoryServiceProvider.php`

Register: `Event::listen(VariantSkusGeneratedEvent::class, VariantSkusGeneratedSlackListener::class)`

Register provider in `bootstrap/providers.php`.

### 3F. Event Dispatch
**File:** `app/Presentation/Console/Commands/GenerateVariantSkusCommand.php`

In `displaySuccessResult()`, after the table output, dispatch event when `$result->created > 0`:
```php
event(new VariantSkusGeneratedEvent(
    productId: ..., productTitle: $result->productTitle,
    created: $result->created, skipped: $result->skipped,
    failed: $result->failed, createdSkus: $result->createdSkus,
));
```

---

## Files Summary

### New Files (6)
| File | Layer |
|------|-------|
| `app/Domain/Catalog/Product/Resolvers/StandardSignPriceResolver.php` | Domain |
| `app/Domain/Inventory/Events/VariantSkusGeneratedEvent.php` | Domain |
| `app/Infrastructure/Notifications/Slack/VariantSkusGeneratedNotification.php` | Infrastructure |
| `app/Infrastructure/Notifications/Listeners/VariantSkusGeneratedSlackListener.php` | Infrastructure |
| `app/Providers/InventoryServiceProvider.php` | Bootstrap |
| `tests/Unit/Domain/Catalog/Product/Resolvers/StandardSignPriceResolverTest.php` | Tests |

### Modified Files (7)
| File | Changes |
|------|---------|
| `app/Application/Inventory/Commands/GenerateVariantSkusCommand.php` | +`$copyParentMpn`, +`$isStandardSign`, +`$noSupplier` |
| `app/Application/Inventory/Results/GenerateVariantSkusResult.php` | +`$productTitle` |
| `app/Application/Inventory/UseCases/GenerateVariantSkusUseCase.php` | +2 constructor deps, thread flags, MPN/price/supplier logic |
| `app/Application/Inventory/Params/CreateStockItemParams.php` | Make `$supplierId` nullable |
| `app/Application/Inventory/Services/LinnworksStockItemCreatorService.php` | Conditional supplier in `completeItemSetup()` |
| `app/Application/Inventory/Services/GenerateStockItemFromVariationService.php` | Add `clearCostPrice: true` |
| `app/Domain/Catalog/Product/Commands/UpdateBasicProductCommand.php` | +`$clearCostPrice` |
| `app/Infrastructure/Shopwired/Clients/BasicProductUpdateClient.php` | Handle `clearCostPrice` in payload |
| `app/Presentation/Console/Commands/GenerateVariantSkusCommand.php` | +4 CLI options, event dispatch |
| `app/Infrastructure/Linnworks/Models/StockItemModel.php` | +`suppliers()`, +`defaultSupplier()` |
| `app/Infrastructure/Linnworks/Mappers/StockItemModelMapper.php` | Map suppliers in `fromModel()` |
| `config/shopwired.php` | +`standard_sign_product_id` |

### Test Updates
| File | Changes |
|------|---------|
| `tests/Unit/Application/Inventory/UseCases/GenerateVariantSkusUseCaseTest.php` | Update setUp (2 new mocks), add tests for flags |
| `tests/Unit/Domain/Catalog/Product/Resolvers/StandardSignPriceResolverTest.php` | New: option matching tests |
| `bootstrap/providers.php` | Register `InventoryServiceProvider` |

---

## Verification

1. `make test` - all existing tests still pass
2. `make lint` - passes Pint, PHPStan, PHPArkitect, Deptrac
3. Manual test: `php artisan inventory:generate-variant-skus <id> <sku> --copy-mpn --is-standard-sign`
4. Verify Slack message appears in configured channel (or use `php artisan slack:test`)
5. `make test-coverage` - coverage thresholds met
