# Plan: Best Seller Label Sync

## Context

The business wants "Best Sellers" applied as a `custom_label_4` label on ShopWired products that land in the top 2 popularity rank tiers. The label must also be removed from products that previously qualified but no longer do.

This is a catalog-level sync (popularity → label), analogous to the existing `SyncBestSellersCategoryUseCase` which applies a category membership diff from the same ranking source. The new sync writes to a shared custom field (other processes may co-own `custom_label_4`), so it must read the current field value and perform a list-level merge rather than a full-field replacement.

---

## Design Decisions

- `custom_label_4` is a `ValueList` (shared array field) — "Best Sellers" is appended/removed, never replaces the whole field.
- Merge source: local JSONB from `catalog.products_view` (slightly stale, self-heals on next run). Race condition risk accepted — daily 2am cron, no concurrent writers.
- Idempotency: orchestrator only dispatches products where a change is needed (SQL filters out already-correct state).
- **Do NOT reuse `UpdateProductCustomFieldsJob` / `UpdateProductCustomFieldsUseCase`** — those run `CustomFieldSubmissionValidator` which rejects `null` values for ValueList fields, breaking the removal path. Internal sync writes call `ProductUpdateClientInterface` directly (same pattern as `SetProductFreeDeliveryUseCase`).
- Dedicated `SetProductBestSellerLabelJob` (with `ShouldBeUnique` keyed by product ID) and `SetProductBestSellerLabelUseCase`.
- `ShouldBeUnique` on both the orchestrator job AND the per-product job (keyed by product ID).
- Schedule: daily 04:15 Europe/London in `CatalogScheduleServiceProvider` (15 min after the Best Sellers category sync).

---

## New Files

### `app/Infrastructure/Jobs/Catalog/SyncBestSellerLabelJob.php`
Orchestrator job. Mirrors `SyncBestSellersCategoryJob` exactly.
```php
final class SyncBestSellerLabelJob implements ShouldBeUnique, ShouldQueue
    uniqueId(): 'sync-best-seller-label'
    QueueName::Low, HandleDatabaseExceptions middleware
    tries=3, maxExceptions=2, timeout=120, uniqueFor=3600, backoff=[30,60]
    retryUntil(): now()->addMinutes(45)->toDateTimeImmutable()
    handle(SyncBestSellerLabelUseCase $useCase): void → $useCase->execute()
    @throws DatabaseOperationFailedException, DuplicateRecordException, ExternalServiceUnavailableException
```

### `app/Infrastructure/Jobs/Shopwired/SetProductBestSellerLabelJob.php`
Per-product write job. Mirrors `SetProductFreeDeliveryJob` shape.
```php
final class SetProductBestSellerLabelJob implements ShouldBeUnique, ShouldQueue
    uniqueId(): (string) $this->productId->value
    QueueName::Bulk, HandleApiExceptions + ServiceRateLimiter::shopwiredApiBulk() + ServiceCircuitBreaker::shopwired()
    tries=6, maxExceptions=3, timeout=60, failOnTimeout=true, backoff=[60,300,900]
    retryUntil(): now()->addHours(4)
    public readonly IntId $productId
    /** @var list<string>|null */
    public readonly array|null $targetLabels
    handle(SetProductBestSellerLabelUseCase $useCase): void → $useCase->execute($this->productId, $this->targetLabels)
    @throws ResourceNotAvailableException, InvalidApiRequestException, AuthenticationExpiredException,
            ExternalServiceUnavailableException, InvalidApiResponseException
```

### `app/Application/Catalog/UseCases/SyncBestSellerLabelUseCase.php`
Orchestrator use case.
```
Deps: ProductViewQueryRepositoryInterface, ShopwiredSyncDispatcherInterface, LoggerInterface
@throws DatabaseOperationFailedException, DuplicateRecordException, ExternalServiceUnavailableException
execute():
  1. $changes = $productViewQueryRepo->findBestSellerLabelChanges()
  2. if !$changes->hasChanges() → log + return early
  3. foreach toAdd:  targetLabels = BestSellerLabelTransformer::addLabel($candidate->currentLabels)
                     $dispatcher->dispatchBestSellerLabelUpdate($candidate->productId, $targetLabels)
  4. foreach toRemove: targetLabels = BestSellerLabelTransformer::removeLabel($candidate->currentLabels)
                        $dispatcher->dispatchBestSellerLabelUpdate($candidate->productId, $targetLabels)
  5. log dispatched_add, dispatched_remove counts
```

### `app/Application/Catalog/UseCases/SetProductBestSellerLabelUseCase.php`
Per-product write use case. Calls `ProductUpdateClientInterface` directly — no submission validator (internal sync, not user input).
```php
final readonly class SetProductBestSellerLabelUseCase
    Deps: ProductUpdateClientInterface $updateClient
    @throws ResourceNotAvailableException, InvalidApiRequestException, AuthenticationExpiredException,
            ExternalServiceUnavailableException, InvalidApiResponseException
    execute(IntId $productId, list<string>|null $targetLabels): void
        → $this->updateClient->updateCustomFields($productId->value, [
              BestSellerLabelTransformer::FIELD => $targetLabels
          ])
```
Note: `BestSellerLabelTransformer::FIELD = 'custom_label_4'` (add this constant alongside `LABEL`).

### `app/Application/Catalog/BestSellerLabels/BestSellerLabelTransformer.php`
Pure static transformer.
```php
final class BestSellerLabelTransformer
    const LABEL = 'Best Sellers';

    /** Append label if not already present. */
    public static function addLabel(list<string> $current): list<string>
        → in_array(self::LABEL, $current) ? $current : [...$current, self::LABEL]

    /** Remove label; return null if list becomes empty. */
    public static function removeLabel(list<string> $current): list<string>|null
        → $result = array_values(array_filter($current, fn($v) => $v !== self::LABEL))
        → $result === [] ? null : $result
```

### `app/Application/Catalog/BestSellerLabels/BestSellerLabelChanges.php`
Read result DTO.
```php
final readonly class BestSellerLabelChanges
    public function __construct(
        /** @var list<ProductLabelCandidate> */ public array $toAdd,
        /** @var list<ProductLabelCandidate> */ public array $toRemove,
    )
    public function hasChanges(): bool → $this->toAdd !== [] || $this->toRemove !== []
```

### `app/Application/Catalog/BestSellerLabels/ProductLabelCandidate.php`
Per-product projection from the view.
```php
final readonly class ProductLabelCandidate
    public function __construct(
        public IntId $productId,
        /** @var list<string> */ public array $currentLabels,
    )
```

---

## Modified Files

### `app/Application/Contracts/Catalog/ProductViewQueryRepositoryInterface.php`
Add at the end:
```php
/**
 * Find products whose custom_label_4 Best Sellers label needs to change.
 *
 * To-add:    popularity_rank <= 2 AND "Best Sellers" NOT in custom_label_4
 * To-remove: (popularity_rank IS NULL OR popularity_rank > 2)
 *            AND "Best Sellers" IS in custom_label_4
 *
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 */
public function findBestSellerLabelChanges(): BestSellerLabelChanges;
```

### `app/Infrastructure/Catalog/Repositories/ProductViewQueryRepository.php`
Implement `findBestSellerLabelChanges()`. Already backed by `ProductViewModel` (catalog.products_view) — no new model dependency.

Use `$this->eloquentGateway->query()` (read path — no transaction needed).

```php
// toAdd: popularity_rank <= 2 AND "Best Sellers" NOT currently in the field
// Note: DB:: facade forbidden; use selectRaw + whereRaw with bound params
$toAdd = $this->eloquentGateway->query(static fn(): array => ProductViewModel::query()
    ->selectRaw("external_id, custom_fields->'custom_label_4' AS current_labels")
    ->whereNotNull('popularity_rank')
    ->where('popularity_rank', '<=', 2)
    ->where(static function (Builder $q): void {
        $q->whereRaw("custom_fields->'custom_label_4' IS NULL")
          ->orWhereRaw("NOT (custom_fields->'custom_label_4' @> ?::jsonb)", ['["Best Sellers"]']);
    })
    ->get()
    ->all()
);

// toRemove: rank out-of-range AND "Best Sellers" IS in the field
$toRemove = $this->eloquentGateway->query(static fn(): array => ProductViewModel::query()
    ->selectRaw("external_id, custom_fields->'custom_label_4' AS current_labels")
    ->where(static fn(Builder $q) => $q->whereNull('popularity_rank')->orWhere('popularity_rank', '>', 2))
    ->whereRaw("custom_fields->'custom_label_4' @> ?::jsonb", ['["Best Sellers"]'])
    ->get()
    ->all()
);
```

Map each row to `ProductLabelCandidate`:
```php
new ProductLabelCandidate(
    productId: IntId::fromTrusted($row->external_id),
    currentLabels: \is_string($row->current_labels)
        ? (array) \json_decode($row->current_labels, associative: true)
        : [],
);
```
`current_labels` comes back as a JSON string because the alias bypasses Eloquent's `$casts`. `IntId::fromTrusted` matches the existing precedent in `ProductViewQueryRepository::getCurrentRelatedProducts()` for DB-sourced IDs.

### `app/Application/Contracts/Shopwired/ShopwiredSyncDispatcherInterface.php`
Add:
```php
/**
 * @param list<string>|null $targetLabels Null clears the field entirely.
 */
public function dispatchBestSellerLabelUpdate(IntId $productId, array|null $targetLabels): void;
```

### `app/Infrastructure/Shopwired/Dispatchers/QueuedShopwiredSyncDispatcher.php`
Add:
```php
#[Override]
public function dispatchBestSellerLabelUpdate(IntId $productId, array|null $targetLabels): void
{
    SetProductBestSellerLabelJob::dispatch($productId, $targetLabels);
}
```
Add import: `use App\Infrastructure\Jobs\Shopwired\SetProductBestSellerLabelJob;`

### `app/Providers/Schedule/CatalogScheduleServiceProvider.php`
Add `$this->registerBestSellerLabelSchedule();` call in `boot()`.
Update class docblock to include "Best Sellers label (04:15, custom_label_4 list merge)".
Add:
```php
private function registerBestSellerLabelSchedule(): void
{
    Schedule::job(new SyncBestSellerLabelJob())
        ->name('sync-best-seller-label')
        ->dailyAt('04:15')
        ->timezone('Europe/London')
        ->onOneServer()
        ->withoutOverlapping(30);
}
```
Add import: `use App\Infrastructure\Jobs\Catalog\SyncBestSellerLabelJob;`

---

## Key Reuse Points

| Reused class | Location | Why reused |
|---|---|---|
| `ProductUpdateClientInterface::updateCustomFields` | `app/Application/Contracts/Shopwired/` | Fetch-merge-PUT; preserves all OTHER custom fields |
| `ProductViewModel` (catalog.products_view) | `app/Infrastructure/Catalog/Product/Models/` | Has both `popularity_rank` and `custom_fields` JSONB |
| `SyncBestSellersCategoryJob` shape | `app/Infrastructure/Jobs/Catalog/` | Canonical orchestrator job template |
| `SetProductFreeDeliveryJob/UseCase` shape | `app/Infrastructure/Jobs/Shopwired/` + `app/Application/Catalog/UseCases/` | Canonical per-product write pattern (direct client call, no validator) |

**NOT reused** (despite appearances): `UpdateProductCustomFieldsJob` / `UpdateProductCustomFieldsUseCase` — validator rejects `null` for ValueList, breaking removal path.

---

## Service Provider Binding

`SyncBestSellerLabelUseCase` and `SetProductBestSellerLabelUseCase` have **no scalar constructor parameters** — all deps are interface-typed and already bound (`ProductViewQueryRepositoryInterface`, `ShopwiredSyncDispatcherInterface`, `ProductUpdateClientInterface`, `LoggerInterface`). Laravel's container auto-resolves them; **no new provider binding is needed**.

For reference: `SyncBestSellersCategoryUseCase` lives in `app/Providers/CatalogServiceProvider.php` → `registerBestSellersBindings()` only because it needs two scalar int params (`$bestSellersLimit`, `$bestSellersCategoryId`) from config. Our new use cases have no such params.

---

## Verification

1. **Unit tests** (new):
   - `BestSellerLabelTransformerTest` — test `addLabel` (normal case, already-present guard), `removeLabel` (remove one of many, remove last → null), edge cases (empty array).
   - `SyncBestSellerLabelUseCaseTest` — mock repo returning `BestSellerLabelChanges`, assert dispatcher called with correct arguments; test no-changes early exit.

2. **Integration test** (new):
   - `ProductViewQueryRepositoryBestSellerLabelTest` — seed `catalog.products_view` rows with known `popularity_rank` and `custom_fields`, assert `findBestSellerLabelChanges()` returns the correct toAdd/toRemove split.

3. **Manual smoke test**:
   ```bash
   php artisan tinker --execute="SyncBestSellerLabelJob::dispatch();"
   ```
   Then check `storage/logs/laravel.log` for `dispatched_add` / `dispatched_remove` counts and `storage/logs/octane.log` for any API rejections.

4. **Linters**: `make lint` must pass (PHPStan max, PHPArkitect, Deptrac).
