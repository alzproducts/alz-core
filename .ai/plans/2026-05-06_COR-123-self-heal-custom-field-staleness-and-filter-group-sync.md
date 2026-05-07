# Plan: Custom Fields & Filter Groups Self-Heal (Sentry ALZ-CORE-AR)

## Context

Recurring 500s on `GET /api/{brands,categories,products}/{id}/custom-fields` when ShopWired admin changes field types but local definitions haven't synced yet. Throw site: `CustomFieldValueFactory` — `InvalidCustomFieldValueException` when JSONB value type doesn't match synced definition. User reports this happens frequently and is "almost always fixed by dispatching the SyncShopwiredCustomFieldsJob."

Filter groups have a silent variant — `FilterGroupRegistry::findByOptionNo` returns null on unknown groups, causing stale faceted nav with no error signal.

Two-layer fix: (1) self-heal dispatch on exception, (2) proactive sync via schedule + deploy-time dispatch.

## Implementation Steps

### Step 1: Add dispatcher methods to interface + implementation

**Files:**
- `app/Application/Contracts/Shopwired/ShopwiredSyncDispatcherInterface.php` — add `dispatchCustomFieldsSync(): void` and `dispatchFilterGroupsSync(): void` (no parameters — these are full-sync dispatches)
- `app/Infrastructure/Shopwired/Dispatchers/QueuedShopwiredSyncDispatcher.php` — implement both. Pattern: single-line `SyncShopwiredCustomFieldsJob::dispatch()` / `SyncShopwiredFilterGroupsJob::dispatch()` (same as existing `dispatchAllProductsSync()` at line 70)

### Step 2: Create the callable wrapper service

**New file:** `app/Application/Catalog/Services/CustomFieldStalenessRecovery.php`

```
final readonly class CustomFieldStalenessRecovery
```

- Constructor: `ShopwiredSyncDispatcherInterface $dispatcher`, `LoggerInterface $logger`
- Single public method: `withRecovery(callable $work): mixed`
  - `@template T`, `@param callable(): T $work`, `@return T`
  - `@throws InvalidCustomFieldValueException` (rethrown after dispatch)
  - try/catch around `$work()` — on `InvalidCustomFieldValueException`:
    1. Log warning with exception context (`fieldName`, `expectedType`, `actualType`)
    2. `$this->dispatcher->dispatchCustomFieldsSync()`
    3. Rethrow `$e`
- No interface needed (single implementation, same layer, no polymorphism)

### Step 3: Wire wrapper into three use cases

**Files:**
- `app/Application/Catalog/UseCases/GetBrandCustomFieldsUseCase.php`
- `app/Application/Catalog/UseCases/GetCategoryCustomFieldsUseCase.php`
- `app/Application/Catalog/UseCases/GetProductCustomFieldsUseCase.php`

Each use case:
1. Add `CustomFieldStalenessRecovery $recovery` constructor parameter
2. Wrap the body of `execute()` in `return $this->recovery->withRecovery(function () use (...): array { ... });`
3. The `@throws InvalidCustomFieldValueException` docblock stays — the wrapper rethrows

Example for Category (line 51-67 of current file):
```php
public function execute(int $categoryId, array $fieldNames = []): array
{
    return $this->recovery->withRecovery(function () use ($categoryId, $fieldNames): array {
        $this->logStart($categoryId, $fieldNames);
        $category = $this->categoryRepository->findCategoryForApi(...);
        $definitions = $this->customFieldRepository->findByItemType(...);
        $fields = CustomFieldMergerService::mergeWithDefinitions(...);
        $fields = self::filterByNames($fields, $fieldNames);
        $this->logEnd($categoryId, count($fields));
        return $fields;
    });
}
```

Brand and Product follow the same pattern — only the entity type and repository differ.

### Step 4: Register filter group schedule

**File:** `app/Providers/Schedule/ShopwiredScheduleServiceProvider.php`

1. Add `use App\Infrastructure\Jobs\Shopwired\SyncShopwiredFilterGroupsJob;` import
2. Add `$this->registerFilterGroupSchedule();` call in `boot()` (after `registerCustomFieldSchedule()`)
3. Add new private method mirroring `registerCustomFieldSchedule()` (lines 122-130):

```php
private function registerFilterGroupSchedule(): void
{
    Schedule::job(new SyncShopwiredFilterGroupsJob())
        ->name('sync-shopwired-filter-groups')
        ->hourly()
        ->timezone('Europe/London')
        ->onOneServer()
        ->withoutOverlapping(5);
}
```

### Step 5: Create deploy-time dispatch artisan command

**New file:** `app/Presentation/Console/Commands/DispatchBaselineSyncsCommand.php`

```
final class DispatchBaselineSyncsCommand extends Command
```

- Signature: `app:dispatch-baseline-syncs`
- Description: `Dispatch baseline definition sync jobs (custom fields + filter groups)`
- `handle()`: inject `ShopwiredSyncDispatcherInterface`, call `dispatchCustomFieldsSync()` + `dispatchFilterGroupsSync()`, output info lines, return `self::SUCCESS`
- Catch `Throwable` → `$this->error(...)` + return `self::FAILURE` (entrypoint checks exit code)
- No `--dry-run` needed (dispatching jobs is idempotent via ShouldBeUnique)

### Step 6: Add deploy hook to docker-entrypoint.sh

**File:** `docker-entrypoint.sh`

Insert after the event:cache block (after line 154 `log_success "Laravel optimization completed"`) and before the Octane configuration summary (line 161):

```bash
# ------------------------------------------------------------------------------
# Baseline sync dispatch (optional, set DISPATCH_BASELINE_SYNCS=true to enable)
# ------------------------------------------------------------------------------
if [ "${DISPATCH_BASELINE_SYNCS}" = "true" ]; then
    log_info "Dispatching baseline sync jobs..."
    if php artisan app:dispatch-baseline-syncs; then
        log_success "Baseline sync jobs dispatched"
    else
        log_warning "Failed to dispatch baseline sync jobs (non-fatal)"
    fi
else
    log_info "Baseline sync dispatch disabled (set DISPATCH_BASELINE_SYNCS=true to enable)"
fi
```

Non-fatal: failure logs warning but does NOT `exit 1`. Container starts regardless.

### Step 7: Fix stale docstring

**File:** `app/Infrastructure/Jobs/Shopwired/SyncShopwiredCustomFieldsJob.php`

Line 29: Change `Recommended scheduling: Weekly (definitions rarely change)` → `Scheduled: Hourly (see ShopwiredScheduleServiceProvider)`

## Verification

1. `make lint` — PHPStan, Pint, PHPArkitect, Deptrac all pass
2. `make test` — all existing tests pass
3. Manual verification: `php artisan app:dispatch-baseline-syncs` dispatches both jobs (check `storage/logs/laravel.log`)
4. Verify `ShopwiredSyncDispatcherInterface` has `@throws` docblocks propagated correctly if needed (dispatcher methods are void + fire-and-forget — no checked exceptions to propagate)

## Files Summary (10 files, 2 new)

| File | Action |
|------|--------|
| `Application/Contracts/Shopwired/ShopwiredSyncDispatcherInterface.php` | Add 2 methods |
| `Infrastructure/Shopwired/Dispatchers/QueuedShopwiredSyncDispatcher.php` | Implement 2 methods |
| `Application/Catalog/Services/CustomFieldStalenessRecovery.php` | **New** — callable wrapper |
| `Application/Catalog/UseCases/GetBrandCustomFieldsUseCase.php` | Wire wrapper |
| `Application/Catalog/UseCases/GetCategoryCustomFieldsUseCase.php` | Wire wrapper |
| `Application/Catalog/UseCases/GetProductCustomFieldsUseCase.php` | Wire wrapper |
| `Providers/Schedule/ShopwiredScheduleServiceProvider.php` | Add filter group schedule |
| `Infrastructure/Jobs/Shopwired/SyncShopwiredCustomFieldsJob.php` | Fix stale docstring |
| `Presentation/Console/Commands/DispatchBaselineSyncsCommand.php` | **New** — deploy-time command |
| `docker-entrypoint.sh` | Add deploy hook |
