# Plan: POST /api/products/{id}/refresh

## Context

The frontend needs a synchronous endpoint to refresh a product's underlying data (ShopWired product + Linnworks stock items) and know when it's safe to invalidate its cache. Currently these two syncs exist independently — this endpoint chains them for a single product including all its variation SKUs.

## Data Flow

```
POST /api/products/{productId}/refresh
  → RefreshProductUseCase::execute(IntId)
    1. ProductSyncService::refreshById(productId)         → 1 ShopWired API call
    2. Product::allSkus()                                  → extract master + variation SKUs
    3. InventoryClientInterface::resolveStockItemIds(skus)  → 1 Linnworks API call (batch)
    4. InventoryClientInterface::getStockItemsFullByIds(guids) → 1 Linnworks API call (batch, NEW)
    5. StockItemRepositoryInterface::saveMany(stockItems)   → iterative save (existing)
  ← 200 OK
```

**Total external API calls: 3** (1 ShopWired + 2 Linnworks), regardless of SKU count.

## Files to Create

### 1. `app/Application/Catalog/UseCases/RefreshProductUseCase.php`

Orchestrates both syncs. Lives in `Catalog` namespace because it's a product-centric operation spanning both integrations.

```php
final readonly class RefreshProductUseCase
{
    public function __construct(
        private ProductSyncService $productSync,
        private InventoryClientInterface $inventoryClient,
        private StockItemRepositoryInterface $stockItemRepository,
        private LoggerInterface $logger,
    ) {}

    public function execute(IntId $productId): void
    {
        // 1. Sync ShopWired product (+ variations, images, custom fields, filters)
        $product = $this->productSync->refreshById($productId->value);

        // 2. Extract all SKUs (master + variations)
        $allSkus = $product->allSkus();
        if ($allSkus === []) {
            return; // No SKUs to sync in Linnworks
        }

        // 3. Batch resolve SKUs → Linnworks GUIDs (1 API call)
        $skuToGuid = $this->inventoryClient->resolveStockItemIds($allSkus);
        if ($skuToGuid === []) {
            // No matching stock items in Linnworks — log and return
            // This is normal for new products not yet in Linnworks
            $this->logger->info('No Linnworks stock items found for product SKUs', [
                'product_id' => $productId->value,
                'sku_count' => count($allSkus),
            ]);
            return;
        }

        // 4. Batch fetch full stock items (1 API call) — NEW public method
        $stockItems = $this->inventoryClient->getStockItemsFullByIds(array_values($skuToGuid));

        // 5. Persist all stock items (calls save() per item, handles extended props + suppliers)
        $this->stockItemRepository->saveMany($stockItems);
    }
}
```

**Error handling**: Let exceptions bubble. The global exception handler maps them to appropriate HTTP responses (503 for transient, 502 for permanent). No partial-success semantics needed — this is an all-or-nothing refresh.

### 2. Route addition in `routes/api.php`

```php
Route::post('products/{productId}/refresh', [ProductUpdateController::class, 'refresh'])
    ->whereNumber('productId');
```

### 3. Controller method on `ProductUpdateController`

```php
public function refresh(int $productId): JsonResponse
{
    $this->refreshUseCase->execute(IntId::from($productId));

    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

204 No Content — consistent with other mutation endpoints that don't return data. The frontend just needs to know it completed (any non-error status).

## Files to Modify

### 4. `app/Application/Contracts/Linnworks/InventoryClientInterface.php` — add method

```php
/**
 * Fetch multiple full stock items by their Linnworks GUIDs in a single API call.
 *
 * @param list<Guid> $stockItemIds
 * @return list<StockItemFull>
 *
 * @throws ResourceNotFoundException When resource not found
 * @throws AuthenticationExpiredException When credentials are invalid
 * @throws ExternalServiceUnavailableException When API is unavailable
 * @throws InvalidApiRequestException When request parameters are invalid
 * @throws InvalidApiResponseException When API response structure is invalid
 */
public function getStockItemsFullByIds(array $stockItemIds): array;
```

### 5. `app/Infrastructure/Linnworks/Clients/InventoryClient.php` — implement + expose

Promote the existing `private fetchStockItemsFullByIds(list<string>)` logic into the new public method, adapting to accept `list<Guid>`:

```php
public function getStockItemsFullByIds(array $stockItemIds): array
{
    if ($stockItemIds === []) {
        return [];
    }

    return $this->fetchStockItemsFullByIds(
        array_map(static fn(Guid $id): string => $id->value, $stockItemIds),
    );
}
```

The existing private `fetchStockItemsFullByIds` stays as-is (used by `getStockItemFull` too).

### 6. `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — add constructor param + method

Add `RefreshProductUseCase` to constructor, add `refresh()` method.

## What We're NOT Creating

- **No request DTO** — no body params, `productId` comes from route
- **No result object** — void use case, 204 response
- **No new repository methods** — `saveMany()` already exists on `RepositoryWriteInterface`
- **No new exceptions** — existing exception hierarchy covers all cases

## Verification

1. `make lint` — PHPStan, Pint, PHPArkitect, Deptrac all pass
2. `make test` — existing tests pass (no broken contracts)
3. Manual test: `curl -X POST http://localhost:8000/api/products/{id}/refresh -H "X-Local-Bypass: $API_BYPASS_SECRET"` — returns 204
4. Verify data freshness: GET the product after refresh, confirm updated timestamps
