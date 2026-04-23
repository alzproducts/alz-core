# Plan — Issue #349: Split `RecordNotFoundException` (transient) from `ResourceNotFoundException` (permanent)

## Context

**Problem.** `ResourceNotFoundException` is currently used for two fundamentally different failure modes:

| Context | Service name | Meaning | Desired retry behaviour |
|---|---|---|---|
| External API 404 (Linnworks, ShopWired, ReviewsIO) | `'Linnworks'`, `'ShopWired'`, `'ReviewsIO'` | Resource genuinely doesn't exist | None — permanent failure |
| Local DB row not found | `'Database'` | Row missing, usually a race condition | Retry with backoff |

Because `ResourceNotFoundException extends PermanentApiFailure`, both contexts fail immediately. The `ShopwiredProductRepository::syncVariations()` delete+insert transaction (`app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php:913-944`) races against concurrent `product.stock_changed` handlers, producing transient `ResourceNotFoundException('Database', 'ProductVariation', …)` that manifests in Sentry as [`ALZ-CORE-5M`](https://alzproducts-mx.sentry.io/issues/ALZ-CORE-5M).

**Outcome.** A new sibling exception, `RecordNotFoundException extends TransientApiFailure`, will be thrown from all `'Database'` call sites. Jobs running through `HandleApiExceptions` middleware will release-and-retry with the configured backoff instead of failing immediately. No middleware changes are needed — `HandleApiExceptions` already catches `TransientApiFailure`, and `HandleDatabaseExceptions` only catches `PermanentApiFailure` (the transient exception bubbles to Laravel's Worker for default-backoff retry on jobs that use only the DB middleware).

**Complexity label (from issue).** `complexity:medium` — spans a few files, follows the established exception-hierarchy pattern.

---

## Approach

Minimal-touch: add one exception, swap 12 throw sites, swap the catch side to match, propagate `@throws` docblocks so PHPStan max + ShipMonk stays green. No middleware, no job config, no behavioural change for the API-404 call sites.

### 1. Create the new exception

**File.** `app/Domain/Exceptions/Api/RecordNotFoundException.php` — co-located with `ResourceNotFoundException.php` so future readers find both siblings next to their base classes.

**Shape** (mirrors `ResourceNotFoundException` but with `'Database'` baked in and an optional `retryAfter`):

```php
final class RecordNotFoundException extends TransientApiFailure
{
    public function __construct(
        public readonly string $resourceType,
        public readonly int|string $resourceId,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            serviceName: 'Database',
            retryAfter: $retryAfter,
            message: 'Record not found in database',
            previous: $previous,
        );
    }

    public function context(): array
    {
        return [
            ...parent::context(),
            'resource_type' => $this->resourceType,
            'resource_id' => $this->resourceId,
        ];
    }
}
```

**Design notes.**
- Message is static (Sentry-grouping requirement per `app/Domain/CLAUDE.md`).
- `$serviceName` is dropped from the constructor — always `'Database'`, so forcing callers to pass it adds nothing.
- `$retryAfter` defaults to `null`: jobs using `HandleApiExceptions` fall back to the job's own `$backoff` array. Call sites that know the race resolves quickly can pass a small hint (e.g. `5`) if desired, but none are required in this refactor.
- `final` class, matching the existing convention for concrete API exceptions.

### 2. Swap the 12 throw sites (all `new ResourceNotFoundException('Database', …)`)

Replace with `new RecordNotFoundException($type, $id, previous: $e)` where applicable (drop the `'Database'` arg). Files and line numbers (from the exploration pass — verify before each edit):

| File | Sites |
|---|---|
| `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php` | 143, 243, 267, 300, 322 |
| `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php` | 668, 703, 892 |
| `app/Infrastructure/Shopwired/Repositories/EloquentBrandRepository.php` | 112 |
| `app/Infrastructure/Shopwired/Repositories/EloquentCategoryRepository.php` | 117 |
| `app/Infrastructure/Shopwired/Repositories/EloquentCustomerRepository.php` | 118 |
| `app/Infrastructure/Database/EloquentGateway.php` | 174 |

**Also.** Update the `use` statements and `@throws` docblocks on these methods to reference `RecordNotFoundException` instead of `ResourceNotFoundException`.

### 3. Swap the DB-path catch sites

The catches below fire on the *idempotent delete* path — the repo raises "row not found" when the webhook re-fires for an already-deleted record. They must be updated to catch `RecordNotFoundException`:

| File | Notes |
|---|---|
| `app/Application/Shopwired/UseCases/Webhooks/DeleteOrderUseCase.php:45` | `catch (ResourceNotFoundException)` → `catch (RecordNotFoundException)` |
| `app/Application/Shopwired/UseCases/Webhooks/DeleteBrandUseCase.php` | same |
| `app/Application/Shopwired/UseCases/Webhooks/DeleteCategoryUseCase.php` | same |
| `app/Application/Shopwired/UseCases/Webhooks/DeleteCustomerUseCase.php` | same |
| `app/Application/Shopwired/UseCases/Webhooks/DeleteProductUseCase.php` | same |
| `app/Application/Shopwired/UseCases/Webhooks/DeleteOrderRefundUseCase.php` | same |

**Internal catches inside `EloquentProductRepository`** (e.g. `getProductByAnySku()` at line 687 — `catch (ResourceNotFoundException)` from a call to `$this->getProduct(...)`): swap to `catch (RecordNotFoundException)`. These are catching the repository's *own* DB throws, so they must track the new type. Re-grep inside that file after the throw-site swap and update every such catch.

**Mixed-API/DB catches to audit** — read each and decide: keep existing, add a second catch for `RecordNotFoundException`, or replace:
- `app/Application/Inventory/Services/LinnworksStockItemCreatorService.php` (2 catches — likely API-only, leave alone)
- `app/Application/.../UpdateSkuUseCase.php` (3 catches — likely mixed, probably needs a second `RecordNotFoundException` catch)
- `app/Presentation/Console/Commands/GenerateVariantSkusCommand.php` (1 catch — likely mixed)

**Don't touch**: API-path catches that only ever see external-service 404s (Linnworks, ShopWired API, ReviewsIO). They stay on `ResourceNotFoundException`.

### 4. Propagate `@throws` through the call graph

Per `app/Application/CLAUDE.md`: interfaces must declare every exception the implementation throws.

- Repository interfaces in `app/Application/Contracts/Shopwired/` (`OrderRepositoryInterface`, `ProductRepositoryInterface`, `BrandRepositoryInterface`, `CategoryRepositoryInterface`, `CustomerRepositoryInterface`): any method declaring `@throws ResourceNotFoundException` on a DB-read path must be updated to `@throws RecordNotFoundException`.
- `DatabaseGatewayInterface` (or equivalent) for the `EloquentGateway:174` site.
- Callers that previously propagated `@throws ResourceNotFoundException` from a DB-path method: update to `RecordNotFoundException`.

**Execution.** After the throw-site swap, run `make lint` — PHPStan's `missingType.checkedException` will flag every docblock that still needs updating. Fix iteratively until clean.

### 5. Test updates

**Rework (5 files)** — replace the stubbed `ResourceNotFoundException` with `RecordNotFoundException` (constructor loses the service-name arg):

| File | Stub to change |
|---|---|
| `tests/Unit/Application/Shopwired/UseCases/Webhooks/DeleteBrandUseCaseTest.php` | Database stub |
| `tests/Unit/Application/Shopwired/UseCases/Webhooks/DeleteCategoryUseCaseTest.php` | Database stub |
| `tests/Unit/Application/Shopwired/UseCases/Webhooks/DeleteOrderUseCaseTest.php` | Database stub (line 82) |
| `tests/Unit/Application/Shopwired/UseCases/Webhooks/DeleteOrderRefundUseCaseTest.php` | Database stub |
| `tests/Unit/Application/Shopwired/UseCases/Webhooks/DeleteProductUseCase*Test.php` | if present |

**Add** — a middleware test that asserts `RecordNotFoundException` takes the *transient* retry path, not the permanent-fail path:
- Extend `tests/Unit/Infrastructure/Jobs/Middleware/HandleApiExceptionsTest.php` with a case: job throws `RecordNotFoundException` → `HandleApiExceptions` calls `release($retryAfter)` (or re-throws when null).
- Optionally assert `HandleDatabaseExceptions` does **not** catch `RecordNotFoundException` (exception propagates through middleware).

**Leave alone** — API-side tests that legitimately stub external-service 404s:
- `tests/Unit/Application/Shopwired/UseCases/Webhooks/DeleteOrderUseCaseTest.php` *API* stub (not the DB one)
- `tests/Unit/Application/Inventory/Services/LinnworksStockItemCreatorServiceTest.php`
- `tests/Unit/Presentation/Http/Api/InternalApiExceptionMapperTest.php`
- `tests/Unit/Infrastructure/Notifications/Listeners/ProductPricingUpdatedSlackListenerTest.php`
- `tests/Feature/Infrastructure/Api/ReviewsIoClientTest.php`

**Optional (Domain)** — a tiny `tests/Unit/Domain/Exceptions/Api/RecordNotFoundExceptionTest.php` to pin the `context()` shape, `$serviceName === 'Database'`, `$retryAfter` wiring, and the static message. Cheap and useful for mutation-testing thresholds.

---

## Critical files to modify

- **New**: `app/Domain/Exceptions/Api/RecordNotFoundException.php`
- `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php`
- `app/Infrastructure/Shopwired/Repositories/EloquentProductRepository.php`
- `app/Infrastructure/Shopwired/Repositories/EloquentBrandRepository.php`
- `app/Infrastructure/Shopwired/Repositories/EloquentCategoryRepository.php`
- `app/Infrastructure/Shopwired/Repositories/EloquentCustomerRepository.php`
- `app/Infrastructure/Database/EloquentGateway.php`
- `app/Application/Shopwired/UseCases/Webhooks/Delete{Order,Brand,Category,Customer,Product,OrderRefund}UseCase.php` (6 files)
- `app/Application/Contracts/Shopwired/{Order,Product,Brand,Category,Customer}RepositoryInterface.php` (docblock updates)
- 5–6 test files under `tests/Unit/Application/Shopwired/UseCases/Webhooks/`
- Possibly `tests/Unit/Infrastructure/Jobs/Middleware/HandleApiExceptionsTest.php`

---

## Reusing existing utilities

- `TransientApiFailure` base class (`app/Domain/Exceptions/Api/TransientApiFailure.php`) provides `$retryAfter`, `context()`-with-null-filter, and the `serviceName` chain through `AbstractApiException`. The new exception only adds `resourceType`/`resourceId` — no duplicated plumbing.
- `HandleApiExceptions` middleware (`app/Infrastructure/Jobs/Middleware/HandleApiExceptions.php`) already handles `TransientApiFailure` via `releaseOrRethrow()` — no change.
- `HandleDatabaseExceptions` middleware unchanged — it catches only `PermanentApiFailure|AbstractInfrastructureException`, so the new transient exception transparently bypasses it.

---

## Verification

**Static checks** (run first, iteratively until clean):
```
make lint         # Pint + PHPStan max + PHPArkitect + Deptrac + TLint
```
Expect PHPStan to guide the `@throws`-propagation work in step 4.

**Unit tests**:
```
make test-quick   # fast domain/unit
make test         # full suite (unit + integration)
```

**Targeted middleware check** — make sure the new exception takes the intended path:
```
php artisan test --filter=HandleApiExceptionsTest
php artisan test --filter=DeleteOrderUseCaseTest
```

**Mutation-testing sanity** (optional, for the new domain test):
```
make mutate-domain
```

**End-to-end smoke** (local, no production touch):
1. Start the queue: via the `Queue` run configuration (per project CLAUDE.md).
2. In `tinker`, dispatch a job that will hit the new exception — e.g. synthesise the ShopWired race by deleting a variation row, then dispatching `UpdateShopwiredAddToSaleJob::dispatch(IntId::from(<id>))`.
3. Tail `storage/logs/laravel.log` and `storage/logs/octane.log`. Confirm:
   - Log line: `'Job transient failure, releasing for retry'` with `service=Database`
   - Job re-appears on the queue (released, not failed) until the row is reinstated or `$tries` is exhausted.
   - No `Queue::failing` log for the transient attempts.
4. Re-insert the row; the next retry should succeed.

**Grep verification** — after all edits:
- No occurrences of `new ResourceNotFoundException('Database'` anywhere in `app/` or `tests/`.
- `ResourceNotFoundException` is still referenced only by external-API call sites (Linnworks, ShopWired, ReviewsIO, Catalog) and by tests that stub those API paths.

---

## Out of scope

- Changing job `$backoff` arrays or `$tries` counts.
- Refactoring `syncVariations()` to eliminate the underlying race — that's a deeper change (advisory lock, UPSERT-style sync) tracked separately.
- Renaming `ResourceNotFoundException` for clarity — keeping its name avoids churn in API-side code and tests.
- Migrating the general-purpose `context()` → Sentry grouping story; the new exception just follows the same pattern the existing one already has.
