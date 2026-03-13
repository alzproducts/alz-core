# Fix: Sentry ALZ-CORE-4C — `vatRelief` missing from webhook payload

## Context

ShopWired `product.updated` webhooks don't include embed data like `vatRelief`. The `ProductResponse` DTO requires `vatRelief` as a non-nullable `bool` with no default, causing `CannotCreateData` when Spatie LaravelData tries to construct it.

**Root cause**: `ProductResponse` serves two data contracts — the API client (all embeds guaranteed) and webhooks (embeds optional). These need separate DTOs with different strictness.

**Broader issue**: Other embed fields (`variations`, `images`, `categories`, `customFields`, `filters`) default to `[]` when missing from webhooks, silently overwriting real DB data. The full sync job corrects this within seconds, but the fix should prevent it.

## Approach

**Separate DTOs for separate contracts**:
- `ProductResponse` stays **strict** (unchanged) — API client path fails loudly on missing embeds
- New `ProductWebhookResponse` uses Spatie `Optional` for embed fields — webhook path handles partial data gracefully
- **Domain `Product` stays completely unchanged** — no nullable fields
- Embed-dependent columns are conditionally included/excluded in the DB upsert based on which embeds Spatie detected as present

## Changes

### 1. NEW: `app/Infrastructure/Shopwired/Responses/ProductWebhookResponse.php`

New Spatie LaravelData DTO for webhook payloads. Core scalar fields are required (same as `ProductResponse`). Embed-dependent fields use `Optional`:

```php
#[MapInputName(SnakeCaseMapper::class)]
final class ProductWebhookResponse extends Data
{
    public function __construct(
        // Core fields — required (always in webhooks)
        public readonly int $id,
        public readonly ?string $sku,
        public readonly ?string $gtin,
        public readonly string $title,
        public readonly ?string $description,
        public readonly string $slug,
        public readonly string $url,
        public readonly float $price,
        public readonly ?float $costPrice,
        public readonly ?float $salePrice,
        public readonly ?float $comparePrice,
        public readonly int $stock,
        #[MapInputName('active')]
        public readonly bool $isActive,
        public readonly bool $vatExclusive,
        public readonly ?float $weight,
        public readonly ?string $metaTitle,
        public readonly ?string $metaDescription,
        public readonly string $createdAt,
        public readonly string $updatedAt,

        // Embed fields — Optional (may be absent from webhooks)
        public readonly bool|Optional $vatRelief = new Optional(),
        #[DataCollectionOf(ProductVariationResponse::class)]
        public readonly array|Optional $variations = new Optional(),
        #[DataCollectionOf(ProductImageResponse::class)]
        public readonly array|Optional $images = new Optional(),
        public readonly array|Optional $categories = new Optional(),
        public readonly array|Optional $customFields = new Optional(),
        public readonly array|Optional $filters = new Optional(),
    ) {}

    /**
     * Returns the list of embed names that were actually present in the payload.
     *
     * @return list<string> Embed names (matching ShopWired API embed names)
     */
    public function presentEmbeds(): array
    {
        $embeds = [];
        if (!$this->vatRelief instanceof Optional) $embeds[] = 'vat_relief';
        if (!$this->variations instanceof Optional) $embeds[] = 'variations';
        if (!$this->images instanceof Optional) $embeds[] = 'images';
        if (!$this->categories instanceof Optional) $embeds[] = 'categories';
        if (!$this->customFields instanceof Optional) $embeds[] = 'custom_fields';
        if (!$this->filters instanceof Optional) $embeds[] = 'filters';
        return $embeds;
    }

    /**
     * Same as ProductResponse::getCategoryIds() but handles Optional.
     *
     * @return list<int>
     */
    public function getCategoryIds(): array
    {
        if ($this->categories instanceof Optional) {
            return [];
        }
        return array_map(
            static fn(array $category): int => $category['id'],
            $this->categories,
        );
    }
}
```

### 2. NEW: `app/Application/Shopwired/DTOs/WebhookProductResult.php`

Small DTO to carry the parsed product + which embeds were present:

```php
final readonly class WebhookProductResult
{
    /**
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     */
    public function __construct(
        public Product $product,
        public array $presentEmbeds,
    ) {}
}
```

### 3. `app/Application/Contracts/Shopwired/ProductWebhookParserInterface.php`

Change `parseProduct` return type:

```php
// Before
public function parseProduct(array $data): Product;

// After
public function parseProduct(array $data): WebhookProductResult;
```

### 4. `app/Infrastructure/Shopwired/Parsers/ShopwiredProductWebhookParser.php`

- Use `ProductWebhookResponse` instead of `ProductResponse`
- Return `WebhookProductResult` with present embeds
- **Harden catch block**: add `CannotCreateData` alongside `TypeError` (the actual exception from the Sentry event was `CannotCreateData`, which is NOT a `TypeError` subclass)

```php
use Spatie\LaravelData\Exceptions\CannotCreateData;

public function parseProduct(array $data): WebhookProductResult
{
    try {
        $response = ProductWebhookResponse::from($data['object']);
        return new WebhookProductResult(
            product: $this->factory->fromWebhookResponse($response),
            presentEmbeds: $response->presentEmbeds(),
        );
    } catch (TypeError|CannotCreateData $e) {
        Log::error('ShopWired product webhook payload type mismatch', ['error' => $e->getMessage()]);
        throw new InvalidApiResponseException('ShopWired', previous: $e);
    }
}
```

### 5. `app/Infrastructure/Shopwired/Factories/ProductDomainFactory.php`

Add new `fromWebhookResponse()` method. Handles `Optional` → default conversion so the `Product` domain stays non-nullable:

```php
public function fromWebhookResponse(ProductWebhookResponse $response): Product
{
    return new Product(
        // Core fields — same as fromResponse()
        id: $response->id,
        sku: $response->sku === '' ? null : $response->sku,
        gtin: $this->buildGtin($response->gtin, $response->id),
        title: $response->title,
        // ... all other core fields identical ...

        // Embed fields — defaults when Optional (values unused for DB, just satisfy constructor)
        vatRelief: $response->vatRelief instanceof Optional ? false : $response->vatRelief,
        categoryIds: $response->getCategoryIds(),
        variations: $response->variations instanceof Optional
            ? []
            : $this->buildVariations($response->id, $response->variations),
        images: $response->images instanceof Optional
            ? []
            : $this->buildImages($response->images),
        rawCustomFields: $response->customFields instanceof Optional ? [] : $response->customFields,
        rawFilters: $response->filters instanceof Optional ? [] : $response->filters,
        customFields: [],
        filters: [],
        createdAt: CarbonImmutable::parse($response->createdAt)->toDateTimeImmutable(),
        updatedAt: CarbonImmutable::parse($response->updatedAt)->toDateTimeImmutable(),
    );
}
```

### 6. `app/Infrastructure/Shopwired/Mappers/ProductModelMapper.php`

Add `toWebhookAttributes()` that takes `$presentEmbeds` and conditionally includes embed columns:

```php
/**
 * Convert Domain Product to model attributes for webhook partial save.
 *
 * Only includes embed-dependent columns that were actually present
 * in the webhook payload. Core scalar fields are always included.
 *
 * @param list<string> $presentEmbeds Embed names present in webhook payload
 * @return array<string, mixed>
 */
public static function toWebhookAttributes(Product $product, array $presentEmbeds): array
{
    $attributes = [
        // Core scalar fields — always included
        'external_id' => $product->id,
        'sku' => $product->sku,
        'gtin' => $product->gtin?->value,
        'title' => $product->title,
        'description' => $product->description,
        'slug' => $product->slug,
        'url' => $product->url,
        'price' => $product->price,
        'cost_price' => $product->costPrice,
        'sale_price' => $product->salePrice,
        'compare_price' => $product->comparePrice,
        'stock' => $product->stock,
        'is_active' => $product->isActive,
        'vat_exclusive' => $product->vatExclusive,
        'weight' => $product->weight,
        'meta_title' => $product->metaTitle,
        'meta_description' => $product->metaDescription,
        'shopwired_created_at' => $product->createdAt,
        'shopwired_updated_at' => $product->updatedAt,
    ];

    // Embed-dependent columns — only if present in webhook payload
    if (in_array('vat_relief', $presentEmbeds, true)) {
        $attributes['vat_relief'] = $product->vatRelief;
    }
    if (in_array('categories', $presentEmbeds, true)) {
        $attributes['category_ids'] = $product->categoryIds;
    }
    if (in_array('images', $presentEmbeds, true)) {
        $attributes['images'] = array_map(
            static fn(ProductImage $img): array => $img->toArray(),
            $product->images,
        );
    }
    if (in_array('custom_fields', $presentEmbeds, true)) {
        $attributes['custom_fields'] = $product->rawCustomFields;
    }
    if (in_array('filters', $presentEmbeds, true)) {
        $attributes['filters'] = $product->rawFilters;
    }

    return $attributes;
}
```

### 7. `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`

Update `saveFromWebhook()` to accept present embeds and use webhook mapper:

```php
// Interface signature change:
public function saveFromWebhook(Product $product, DateTimeImmutable $webhookAt, array $presentEmbeds = []): void;

// Implementation:
public function saveFromWebhook(Product $product, DateTimeImmutable $webhookAt, array $presentEmbeds = []): void
{
    $this->performWebhookSave($product, $presentEmbeds, ['shopwired_webhook_at' => $webhookAt]);
}
```

New `performWebhookSave()` method (separate from `performSave` which stays for full API syncs):

```php
private function performWebhookSave(Product $product, array $presentEmbeds, array $extra = []): void
{
    try {
        $this->eloquentGateway->transact(function () use ($product, $presentEmbeds, $extra): void {
            $this->eloquentGateway->upsertOne(
                modelClass: self::MODEL_CLASS,
                attributes: [
                    ...ProductModelMapper::toWebhookAttributes($product, $presentEmbeds),
                    ...$extra,
                ],
                uniqueBy: ['external_id'],
            );

            // Only sync variations if they were present in the webhook
            if (in_array('variations', $presentEmbeds, true) && $product->variations !== null) {
                $this->syncVariations($product);
            }
        }, attempts: 3);
    } catch (DatabaseOperationFailedException $e) {
        $this->logCrossTableSkuConflictIfApplicable($e, $product);
        throw $e;
    }
}
```

### 8. `app/Application/Contracts/Shopwired/ProductRepositoryInterface.php`

Update signature:

```php
// Before
public function saveFromWebhook(Product $product, DateTimeImmutable $webhookAt): void;

// After
/**
 * @param list<string> $presentEmbeds Embed names present in webhook payload
 */
public function saveFromWebhook(Product $product, DateTimeImmutable $webhookAt, array $presentEmbeds = []): void;
```

### 9. `app/Application/Shopwired/Services/HandleProductWebhookService.php`

Thread present embeds from parser result through to use case:

```php
// Before (line 69-73)
ProductWebhookIntent::Sync => $this->syncProductUseCase->execute(
    eventTime: $eventTime,
    webhookId: $webhookId,
    product: $this->productParser->parseProduct($data),
),

// After
ProductWebhookIntent::Sync => $this->executeSyncProduct($eventTime, $webhookId, $data),

// New private method:
private function executeSyncProduct(DateTimeImmutable $eventTime, int $webhookId, array $data): void
{
    $result = $this->productParser->parseProduct($data);
    $this->syncProductUseCase->execute(
        eventTime: $eventTime,
        webhookId: $webhookId,
        product: $result->product,
        presentEmbeds: $result->presentEmbeds,
    );
}
```

### 10. `app/Application/Shopwired/UseCases/Webhooks/SyncProductUseCase.php`

Accept and pass present embeds:

```php
// Before (line 36)
public function execute(DateTimeImmutable $eventTime, int $webhookId, Product $product): void

// After
/**
 * @param list<string> $presentEmbeds Embed names present in webhook payload
 */
public function execute(DateTimeImmutable $eventTime, int $webhookId, Product $product, array $presentEmbeds = []): void

// Line 57 change:
$this->productRepository->saveFromWebhook($product, $eventTime, $presentEmbeds);
```

### 11. New migration: harden embed columns

```sql
-- vat_relief: make nullable (null = "unknown/not yet synced", false = "confirmed not eligible")
ALTER TABLE shopwired.products ALTER COLUMN vat_relief DROP NOT NULL;

-- JSONB embed columns: add defaults for INSERT safety
ALTER TABLE shopwired.products ALTER COLUMN category_ids SET DEFAULT '[]';
ALTER TABLE shopwired.products ALTER COLUMN images SET DEFAULT '[]';
ALTER TABLE shopwired.products ALTER COLUMN custom_fields SET DEFAULT '{}';
-- filters already has DEFAULT '{}' from its creation migration
```

**vat_relief nullable rationale**: `false` means "product is confirmed NOT eligible for VAT relief." Writing `false` when we don't know is semantically wrong and could affect tax calculations. `null` = "unknown, awaiting full sync." The full API sync (`SyncShopwiredProductJob`) writes the real value within seconds.

**JSONB columns**: Empty arrays/objects are valid defaults — "no categories yet" = `[]`. Unlike vat_relief where false asserts something specific.

### 12. `app/Infrastructure/Shopwired/Mappers/ProductModelMapper.php` — read path

Update `toDomain()` to handle nullable `vat_relief` from DB:

```php
// Before (line 72)
vatRelief: $model->vat_relief,

// After
vatRelief: $model->vat_relief ?? false,
```

Domain `Product::vatRelief` stays non-nullable `bool`. The `?? false` fallback only applies during the brief window before full sync. Safe default: don't grant VAT relief unless confirmed.

### 13. `app/Infrastructure/Shopwired/Models/ProductModel.php` — cast update

Update the `vat_relief` cast to handle nullable:

```php
// Before
'vat_relief' => 'boolean',

// After — nullable boolean, null = unknown
'vat_relief' => 'boolean',  // Laravel boolean cast handles null gracefully
```

No change needed — Laravel's boolean cast returns `null` for null DB values, which the mapper handles with `?? false`.

### 14. Unchanged files

- `ProductResponse.php` — **strict, unchanged**. API client path still fails loudly on missing embeds.
- `Product` domain object — **unchanged**. No nullable fields. Non-nullable `bool $vatRelief`.
- `ProductModelMapper::toModelAttributes()` — **unchanged**. Full save path (API sync) still writes all columns.

## Verification

1. `make fix` — auto-fix Pint style issues
2. `make lint` — PHPStan/Pint/PHPArkitect/Deptrac pass
3. `make test` — no regressions
4. Verify: `ProductWebhookResponse::from([...24 core fields without vatRelief...])` succeeds with `vatRelief` as `Optional`
5. Verify: `ProductWebhookResponse::presentEmbeds()` returns only the embeds that were in the payload
6. Verify: `ProductModelMapper::toWebhookAttributes()` excludes embed columns not in `$presentEmbeds`
7. Verify: `EloquentProductRepository::performWebhookSave()` skips `syncVariations()` when variations not in present embeds

---

## Follow-up: Harden ProductResponse (remove embed defaults)

**Separate PR** — `ProductResponse` currently has `= []` defaults for embed arrays (`variations`, `images`, `categories`, `customFields`, `filters`). This means the API client path would silently accept missing embeds and overwrite DB data with empty arrays.

**Fix**: Remove `= []` defaults from `ProductResponse` embed fields, making them required. If the API response is missing an embed the client requested, Spatie throws `CannotCreateData` — the correct behavior.

```php
// Before (ProductResponse)
public readonly array $variations = [],
public readonly array $images = [],
public readonly array $categories = [],
public readonly array $customFields = [],
public readonly array $filters = [],

// After — required, no defaults
public readonly array $variations,
public readonly array $images,
public readonly array $categories,
public readonly array $customFields,
public readonly array $filters,
```

**Scope**: Audit all ShopWired entity response DTOs (Orders, Customers, etc.) for the same pattern and harden where applicable.

## Follow-up: Audit CannotCreateData across all parsers

**Separate PR** — The Sentry event showed `CannotCreateData` (Spatie exception) was thrown but the parser only caught `TypeError`. This gap likely exists in all ShopWired parsers.

**Fix**: Audit all files in `app/Infrastructure/Shopwired/Parsers/` and add `CannotCreateData` to catch blocks alongside `TypeError`:

```php
// Before
} catch (TypeError $e) {

// After
} catch (TypeError|CannotCreateData $e) {
```

Files to audit:
- `ShopwiredProductWebhookParser.php` (fixed in this PR)
- All other `*Parser.php` files in the parsers directory
- Any other infrastructure parsers using Spatie `Data::from()` in try/catch blocks
