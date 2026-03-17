# Plan: ShopWired Batch Price Update Endpoint (#212)

## Context

ShopWired's PUT endpoint (`PUT products/{id}`) silently ignores `salePrice: 0` — returns 200 OK but never clears the sale price. The dedicated `POST /v1/products/prices` endpoint correctly handles `salePrice: 0`, supports batch mode (up to 15 items), and uses SKU-based identification.

This plan has three parts:
1. Remove broken price fields from the existing PUT code path
2. Build a product-scoped price update system with pre-flight checks, validation, and domain events
3. SCD2 price history table (driven by domain events)

**Key architectural decision**: The UseCase operates on a single product (master + variants) as the atomic unit. This is the natural aggregate boundary — events are trivial, error boundaries are clean, and a product's SKUs almost always fit within ShopWired's 15-item batch limit. A future bulk orchestrator can group changes by product and dispatch queued Jobs that each call this UseCase.

---

## Part 1 — Remove price/salePrice from PUT Code Path

Remove `price` and `salePrice` from `UpdateBasicProductCommand` and `BasicProductUpdateClient`. `costPrice` stays (works via PUT, not supported by POST).

### Files to Modify

| File | Change |
|------|--------|
| `app/Domain/Catalog/Product/Commands/UpdateBasicProductCommand.php` | Remove `?Money $price` and `?Money $salePrice` params + `hasAnyUpdate()` refs |
| `app/Infrastructure/Shopwired/Clients/BasicProductUpdateClient.php` | Remove price/salePrice from `buildPayload()` |
| `app/Application/Contracts/Shopwired/BasicProductUpdateClientInterface.php` | Update docblock: non-price attrs + costPrice only |
| `tests/.../UpdateBasicProductCommandTest.php` | Remove price/salePrice test cases |
| `app/Presentation/Console/Commands/TestShopwiredCostPriceCommand.php` | Remove price/salePrice options |

**After**: `UpdateBasicProductCommand` = `identifier`, `type`, `newSku`, `costPrice`, `weight`, `gtin`

---

## Part 2 — Product-Scoped Price Update System

### Domain Layer

**`app/Domain/Catalog/Product/ValueObjects/ProductRetailPricing.php`** (new)
```
final readonly class ProductRetailPricing
    Money $basePrice           — Money::inclusive() selling price (gross)
    ?Money $salePrice          — Money::inclusive() sale price (null = no sale)
    bool $saleActive           — computed: salePrice !== null && !salePrice->isZero()
    Money $effectivePrice      — computed: saleActive ? salePrice : basePrice

Preserves tax-type context for downstream consumers (profit calc, margin reporting).
All prices are Money::inclusive() — ShopWired prices are always gross.

Reused throughout the feature:
  - Pre-flight comparison (current vs proposed)
  - Event payload (previous vs new)
  - SCD2 snapshot computation (extract ->toGross() for decimal storage)
  - Validation (salePrice < basePrice via ->toGross() comparison)
```

**`app/Domain/Catalog/Product/Commands/UpdatePriceCommand.php`** (new)
```
final readonly class UpdatePriceCommand
    Sku $sku              — required (API identifies by SKU)
    ?Money $price         — null = no change
    ?Money $salePrice     — null = no change; Money::inclusive(0) = clear
    hasAnyUpdate(): bool

    Constructor assertion: if BOTH price and salePrice are set AND salePrice > 0,
    assert salePrice->toGross() < price->toGross()
    (command-internal validation, no DB needed)
```

**`app/Domain/Catalog/Product/ValueObjects/PriceUpdateItemResult.php`** (new)
```
final readonly class PriceUpdateItemResult
    string $sku
    bool $updated
    ?IntId $productId    — ShopWired product ID (only when updated: true)
    ?bool $isVariation   — whether SKU is a variation (only when updated: true)

Infrastructure DTO parses raw int from API, toDomain() wraps in IntId::from().
```

**`app/Domain/Catalog/Product/Events/SkuRetailPricingUpdatedEvent.php`** (new)
```
final readonly class SkuRetailPricingUpdatedEvent
    Sku $sku
    ProductRetailPricing $previousPrices    — prices before the update (from DB)
    ProductRetailPricing $newPrices          — prices after the update (confirmed by API)

Dispatched once per SKU that gets updated: true from the API.
Granular per-SKU event. Listeners: SCD2 price period recording.
```

**`app/Domain/Catalog/Product/Events/ProductPricingUpdatedEvent.php`** (new)
```
final readonly class ProductPricingUpdatedEvent
    IntId $productId              — ShopWired product external ID
    list<Sku> $updatedSkus        — which SKUs were updated on this product

Dispatched once after all SKU updates for the product are confirmed.
Product-level event. Listeners (future): profit recalculation, Slack notification.
No listeners in this implementation — event class + dispatch only.
```

### Application Layer

**`app/Domain/Exceptions/Api/PartialBatchFailureException.php`** (new)
```
final class PartialBatchFailureException extends \DomainException
    /** @var list<AbstractApiException> */
    public array $failures              — the actual domain exceptions from failed chunks
    public string $serviceName          — 'Shopwired'

Modelled after .NET's AggregateException — wraps multiple independent failures
from batch operations into a single throwable. Callers inspect $failures to
classify and handle each one individually (transient vs permanent).
```

**`app/Application/Contracts/Shopwired/PriceUpdateClientInterface.php`** (new)
```
interface PriceUpdateClientInterface
    /** @param list<UpdatePriceCommand> $commands Any size — client handles chunking */
    updatePrices(array $commands): list<PriceUpdateItemResult>

    Hybrid error model (AWS/Google batch pattern + AggregateException):
    - Per-item failures (updated: false) → included in returned list
    - Full transport failure (all chunks fail) → throws standard domain exceptions
    - Partial transport failure (some chunks succeed, some fail) →
      returns successful results AND throws PartialBatchFailureException
      wrapping the actual domain exceptions from failed chunks

    @throws InvalidApiRequestException          — all chunks failed (programming error)
    @throws AuthenticationExpiredException       — all chunks failed (auth)
    @throws ExternalServiceUnavailableException  — all chunks failed (transient)
    @throws InvalidApiResponseException          — all chunks failed (contract violation)
    @throws PartialBatchFailureException         — some chunks succeeded, some failed
                                                   $failures contains the domain exceptions
                                                   return value contains successful results
```

**`app/Application/Shopwired/Results/PriceUpdateResult.php`** (new)
```
final readonly class PriceUpdateResult
    int $total
    int $succeeded                                              — API confirmed updated: true
    list<array{sku: string}> $skipped                           — pre-flight: prices unchanged
    list<array{sku: string, error: string}> $permanentFailures  — validation rejected OR API updated: false
    list<array{sku: string, error: string}> $temporaryFailures  — TransientApiFailure
    + helper methods: hasFailures(), allSucceeded(), isPartialSuccess(), etc.

Scoped to a single product's SKUs.
```

**`app/Application/Shopwired/UseCases/UpdateProductPricesUseCase.php`** (new)
```
final readonly class UpdateProductPricesUseCase
    __construct(
        PriceUpdateClientInterface $priceClient,
        ProductRepositoryInterface $productRepo,    ← pre-flight price reads
        LoggerInterface $logger,
    )
    execute(IntId $productId, list<UpdatePriceCommand> $skuUpdates): PriceUpdateResult

    Flow:
    1. Fetch current prices for this product:
       productRepo->getRetailPricingByProductId($productId)
       Returns: array<string, ProductRetailPricing> keyed by SKU (master + all variations)
       Empty result → throw ResourceNotFoundException ("Product {id} not found")

    2. Validate SKU ownership:
       Each command's SKU must exist in the product's pricing data
       Unknown SKUs → permanentFailure ("SKU does not belong to product {id}")

    3. Filter — skip unchanged:
       Compare each command against current prices (2dp rounding via ->toGross())
       ALL requested fields match → add to "skipped" list

    4. Validate — reject invalid price relationships:
       Compute effective base_price: command.price?.toGross() ?? current basePrice
       Compute effective sale_price: command.salePrice?.toGross() ?? current salePrice
       If salePrice > 0 AND salePrice >= basePrice → permanentFailure
         Error: "salePrice (£X) must be less than basePrice (£Y)"
       If salePrice = 0 (clearing) → always valid, skip check

    5. Send validated commands to PriceUpdateClientInterface::updatePrices()
       Client handles chunking into batches of 15 internally
       Returns list<PriceUpdateItemResult> for successful chunks
       updated: false → permanentFailure ("SKU not updated — not found or API rejected")

       Catch PartialBatchFailureException:
         - Extract successful results from return value (before the throw)
         - Classify $e->failures: transient vs permanent
         - Map failed chunks' SKUs into temporaryFailures or permanentFailures
       Catch full transport failures (all chunks failed):
         - Mark ALL remaining SKUs as temporary or permanent failures

    6. For updated: true items → dispatch events:
       a. Per-SKU: dispatch SkuRetailPricingUpdatedEvent for each confirmed SKU
          Construct ProductRetailPricing for previous (from step 1) and new (command + carry-forward)
       b. Per-product: dispatch ProductPricingUpdatedEvent once
          productId known upfront (input parameter), updatedSkus from confirmed results

    7. Return PriceUpdateResult
```

Note: The UseCase operates on a SINGLE product. A future bulk orchestrator groups changes by product and dispatches queued Jobs that each call this UseCase — adding throttling, retries, etc. without modifying the UseCase.

### Infrastructure Layer

**`app/Infrastructure/Shopwired/Responses/PriceUpdateItemResponse.php`** (new)
```
Spatie Data DTO: sku (string), updated (bool), ?productId (int), ?variation (bool)
Implements DomainConvertibleInterface → toDomain(): PriceUpdateItemResult
```

**`app/Infrastructure/Shopwired/Clients/PriceUpdateClient.php`** (new)
```
final readonly class PriceUpdateClient implements PriceUpdateClientInterface
    uses ShopwiredResponseParserTrait
    - Accepts any number of commands, chunks into batches of 15 internally
    - POST products/prices via ShopwiredTransportInterface::post()
    - ALWAYS uses batch mode: {"items": [...], "sendToEbay": false}
    - Money → toGross() for all prices
    - sendToEbay: false (hardcoded)
    - Parses JSON array response via parseArrayToDomain()
    - Returns consolidated list<PriceUpdateItemResult> across all chunks
```

### Pre-flight Price Query

**New method on `ProductRepositoryInterface`**:
```php
/** @return array<string, ProductRetailPricing> keyed by SKU (master + all variations) */
public function getRetailPricingByProductId(IntId $productId): array;
```

Implementation: query the product + its variations by product external ID:
```sql
-- First SELECT: the master product's own prices
SELECT sku, price, sale_price FROM shopwired.products WHERE external_id = ?
UNION
-- Second SELECT: variation prices, with COALESCE to inherit parent price when null
-- 'p' alias is defined by the JOIN in this SELECT (not the first one)
SELECT v.sku, COALESCE(v.price, p.price) AS price, v.sale_price
FROM shopwired.product_variations v
JOIN shopwired.products p ON p.external_id = v.product_external_id
WHERE v.product_external_id = ?
```

Returns `ProductRetailPricing` objects indexed by SKU. Product-scoped — single query for all SKUs of one product.

### Wiring (Part 2)

**`app/Infrastructure/Shopwired/ShopwiredClientFactory.php`**
- Add `createPriceUpdateClient(): PriceUpdateClientInterface`

**`app/Providers/ShopwiredServiceProvider.php`**
- Add `singleton` binding via factory
- Add `PriceUpdateClientInterface::class` to `provides()` array

---

## Part 3 — SCD2 Price History (Event-Driven)

### Database — `operations.price_periods`

Each row = a price period for a SKU. When a price changes, the current row is closed and a new row is inserted in one transaction.

```
Columns:
    id                   UUID PRIMARY KEY DEFAULT gen_random_uuid()
    sku                  VARCHAR(255) NOT NULL
    base_price_gross     DECIMAL(14,6) NOT NULL    — ShopWired selling price (tax-inclusive)
    sale_price_gross     DECIMAL(14,6) NULL        — ShopWired sale price (tax-inclusive, null = no sale)
    effective_price_gross DECIMAL(14,6) NOT NULL   — snapshot: (sale_price_gross > 0) ? sale_price_gross : base_price_gross
    price_has_tax        BOOLEAN NOT NULL           — false if zero-rated (no VAT applies)
    effective_from       TIMESTAMPTZ NOT NULL       — when this price period started (our processing time)
    effective_to         TIMESTAMPTZ NULL           — when this period ended (NULL = current)
    created_at           TIMESTAMPTZ DEFAULT now()

price_has_tax sourced from ProductRetailPricing::taxType(). When the UK VAT rate
eventually changes, a date-based rate lookup table + price_has_tax + effective_from
will derive the correct net price.

Indexes:
    UNIQUE (sku) WHERE effective_to IS NULL    — one "current" row per SKU
    (sku, effective_from)                      — date-range price lookups

Write mechanics (per confirmed SKU, in transaction):
    UPDATE ... SET effective_to = now() WHERE sku = ? AND effective_to IS NULL;
    INSERT ... (sku, base_price_gross, sale_price_gross, effective_price_gross, price_has_tax, effective_from)
    VALUES (?, ..., now());
    First update for a SKU: UPDATE matches nothing → INSERT succeeds.
```

### Listener — RecordPricePeriodListener (thin — like a controller)

**`app/Infrastructure/Operations/Listeners/RecordPricePeriodListener.php`** (new)
```
final class RecordPricePeriodListener implements ShouldQueue
    - Listens to SkuRetailPricingUpdatedEvent
    - Queued — independent of UseCase, gets Laravel retry semantics on failure
    - $tries, $backoff, failed() method (per job conventions)
    - THIN: extracts event data and delegates to RecordPricePeriodUseCase
    - No business logic — just an entry point (same pattern as controllers)
```

### Application Layer

**`app/Application/Operations/UseCases/RecordPricePeriodUseCase.php`** (new)
```
final readonly class RecordPricePeriodUseCase
    __construct(PricePeriodRepositoryInterface $repo)
    execute(Sku $sku, ProductRetailPricing $newPrices): void
    - Constructs full SCD2 record from ProductRetailPricing:
      base_price_gross, sale_price_gross, effective_price_gross, price_has_tax
    - Calls PricePeriodRepositoryInterface::recordPriceChange()
```

**`app/Application/Contracts/Operations/PricePeriodRepositoryInterface.php`** (new)
```
interface PricePeriodRepositoryInterface
    recordPriceChange(string $sku, ProductRetailPricing $pricing): void
    @throws DatabaseOperationFailedException
```

### Infrastructure Layer

**`app/Infrastructure/Operations/Models/PricePeriodModel.php`** (new)
- `$table = 'operations.price_periods'`
- HasUuids, timestamps = false
- Casts: effective_from/effective_to → immutable_datetime, prices → float

**`app/Infrastructure/Operations/Repositories/EloquentPricePeriodRepository.php`** (new)
- Uses DatabaseGateway for transactional writes
- `recordPriceChange()`: atomic UPDATE (close current) + INSERT (new period)

### Wiring (Part 3)

**`app/Providers/DatabaseServiceProvider.php`**
- Add `PricePeriodRepositoryInterface::class` → `EloquentPricePeriodRepository::class`

### Slack Notification

**`app/Infrastructure/Notifications/Listeners/ProductPricingUpdatedSlackListener.php`** (new)
```
final class ProductPricingUpdatedSlackListener implements ShouldQueue
    - Listens to ProductPricingUpdatedEvent
    - Sends to SLACK_VERBOSE_CHANNEL (config('services.slack.notifications.verbose_channel'))
    - $tries = 3, $backoff = 60
    - Follows VariantSkusGeneratedSlackListener pattern
    - Notification includes: product ID, list of updated SKUs
```

**`app/Infrastructure/Notifications/Slack/ProductPricingUpdatedNotification.php`** (new)
- Laravel Slack notification with product ID + updated SKUs summary

### Wiring (Part 3)

**Service provider `boot()`**
- `Event::listen(SkuRetailPricingUpdatedEvent::class, RecordPricePeriodListener::class)`
- `Event::listen(ProductPricingUpdatedEvent::class, ProductPricingUpdatedSlackListener::class)`

---

## Summary of All Changes

| # | Action | File | Layer |
|---|--------|------|-------|
| | **Part 1 — Cleanup** | | |
| 1 | Modify | `app/Domain/Catalog/Product/Commands/UpdateBasicProductCommand.php` | Domain |
| 2 | Modify | `app/Infrastructure/Shopwired/Clients/BasicProductUpdateClient.php` | Infra |
| 3 | Modify | `app/Application/Contracts/Shopwired/BasicProductUpdateClientInterface.php` | Application |
| 4 | Modify | `tests/.../UpdateBasicProductCommandTest.php` | Tests |
| 5 | Modify | `app/Presentation/Console/Commands/TestShopwiredCostPriceCommand.php` | Presentation |
| | **Part 2 — Product-Scoped Price API + Events** | | |
| 6 | Create | `app/Domain/Exceptions/Api/PartialBatchFailureException.php` | Domain |
| 7 | Create | `app/Domain/Catalog/Product/ValueObjects/ProductRetailPricing.php` | Domain |
| 7 | Create | `app/Domain/Catalog/Product/Commands/UpdatePriceCommand.php` | Domain |
| 8 | Create | `app/Domain/Catalog/Product/ValueObjects/PriceUpdateItemResult.php` | Domain |
| 9 | Create | `app/Domain/Catalog/Product/Events/SkuRetailPricingUpdatedEvent.php` | Domain |
| 10 | Create | `app/Domain/Catalog/Product/Events/ProductPricingUpdatedEvent.php` | Domain |
| 11 | Create | `app/Application/Contracts/Shopwired/PriceUpdateClientInterface.php` | Application |
| 12 | Create | `app/Application/Shopwired/Results/PriceUpdateResult.php` | Application |
| 13 | Create | `app/Application/Shopwired/UseCases/UpdateProductPricesUseCase.php` | Application |
| 14 | Create | `app/Infrastructure/Shopwired/Responses/PriceUpdateItemResponse.php` | Infra |
| 15 | Create | `app/Infrastructure/Shopwired/Clients/PriceUpdateClient.php` | Infra |
| 16 | Modify | `app/Infrastructure/Shopwired/ShopwiredClientFactory.php` | Infra |
| 17 | Modify | `app/Providers/ShopwiredServiceProvider.php` (register + provides) | Provider |
| | **Part 3 — SCD2 Price History + Notifications** | | |
| 18 | Create | `database/migrations/..._create_operations_price_periods_table.php` | DB |
| 19 | Create | `app/Application/Contracts/Operations/PricePeriodRepositoryInterface.php` | Application |
| 20 | Create | `app/Application/Operations/UseCases/RecordPricePeriodUseCase.php` | Application |
| 21 | Create | `app/Infrastructure/Operations/Models/PricePeriodModel.php` | Infra |
| 22 | Create | `app/Infrastructure/Operations/Repositories/EloquentPricePeriodRepository.php` | Infra |
| 23 | Create | `app/Infrastructure/Operations/Listeners/RecordPricePeriodListener.php` | Infra |
| 24 | Create | `app/Infrastructure/Notifications/Listeners/ProductPricingUpdatedSlackListener.php` | Infra |
| 25 | Create | `app/Infrastructure/Notifications/Slack/ProductPricingUpdatedNotification.php` | Infra |
| 26 | Modify | `app/Providers/DatabaseServiceProvider.php` (add binding) | Provider |
| 27 | Modify | Service provider boot() (event → listener registrations) | Provider |
| 28 | Modify | `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php` | Application |
| 29 | Modify | `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` | Infra |
| | **Tests** | | |
| 30 | Create | Unit tests for ProductRetailPricing | Tests |
| 31 | Create | Unit tests for UpdatePriceCommand | Tests |
| 32 | Create | Unit tests for PriceUpdateResult | Tests |
| 33 | Create | Unit test for UpdateProductPricesUseCase | Tests |
| 34 | Create | Integration test for PriceUpdateClient (Http::fake) | Tests |
| 35 | Create | Integration test for EloquentPricePeriodRepository | Tests |
| 36 | Create | Unit test for RecordPricePeriodUseCase | Tests |
| 37 | Create | Unit test for RecordPricePeriodListener | Tests |
| 38 | Create | Unit test for ProductPricingUpdatedSlackListener | Tests |
| 39 | Create | Integration test for getRetailPricingByProductId query | Tests |

## Reusable Existing Code

- `Money` — `app/Domain/ValueObjects/Money.php` (price VOs, `->toGross()`)
- `Sku` — `app/Domain/Catalog/Product/ValueObjects/Sku.php`
- `IntId` — `app/Domain/ValueObjects/IntId.php`
- `ShopwiredTransportInterface::post()` — HTTP transport
- `ShopwiredResponseParserTrait` — response parsing
- `DomainConvertibleInterface` — DTO → domain conversion
- `ShopwiredClientFactory` — factory pattern for client creation
- `DatabaseGateway` — transactional write wrapper
- `SkuChangeRepository` / `SkuChangeModel` — pattern reference for operations audit tables
- `VariantSkusGeneratedEvent` + `VariantSkusGeneratedSlackListener` — pattern reference for events
- `NoEventDispatchOutsideApplicationRule` — events must dispatch from Application layer

## Verification

1. `make lint` — Passes (Pint, PHPStan, PHPArkitect, Deptrac)
2. `make test` — All existing + new tests pass
3. `php artisan migrate` — price_periods table created with correct indexes
4. Manual: Use tinker to call UpdateProductPricesUseCase with a test product:
   - Verify salePrice clears correctly (salePrice: 0)
   - Verify price_periods row created with correct effective_price
   - Verify partial unique index enforced (one NULL effective_to per SKU)
   - Verify both events dispatched (SkuRetailPricingUpdated + ProductPricingUpdated)
