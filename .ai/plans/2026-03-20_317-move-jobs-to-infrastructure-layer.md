# Plan: Move Jobs from Application to Infrastructure Layer

## Context

Laravel Jobs are queue delivery mechanisms — they receive a queued message and invoke a UseCase, just like Controllers receive HTTP requests and invoke UseCases. They belong in Infrastructure, not Application.

Currently, 33 jobs live in `app/Application/Jobs/` with a special `ApplicationJobs` sub-layer concept in Deptrac/PHPArkitect that grants them Illuminate framework access. Moving them to Infrastructure:
- Eliminates the sub-layer hack (Infrastructure already has framework access)
- Aligns with Clean Architecture (jobs = delivery mechanism = outer layer)
- Requires dispatcher interfaces so Application can trigger async work without referencing Infrastructure

## Scope

- **33 job classes** + 1 abstract base + 1 enum (`QueueName`)
- **6 custom PHPStan rules** (path detection strings)
- **15 Application UseCases** that dispatch jobs directly (need dispatcher interfaces)
- **8 Schedule Service Providers** + **3 regular Service Providers** (import path updates)
- **9 job test files** (move + namespace updates) + **4 UseCase/integration tests** referencing jobs
- **Architecture configs**: `deptrac.yaml`, `phparkitect.php`, `phpstan.neon`
- **Documentation**: `CLAUDE.md`, `app/Application/CLAUDE.md`

---

## Commit 1: Create Dispatcher Interfaces & Implementations

**Why first**: Establishes the abstraction layer before moving anything. Codebase stays green.

### 5 Dispatcher Interfaces (in `Application/Contracts/{Domain}/`)

| Interface | Location | Methods |
|-----------|----------|---------|
| `ShopwiredSyncDispatcherInterface` | `Application/Contracts/Shopwired/` | `dispatchOrderSync`, `dispatchProductSync`, `dispatchCustomerSync`, `dispatchBrandSync`, `dispatchCategorySync`, `dispatchOrdersRangeSync`, `dispatchFreeDeliveryUpdate` |
| `LinnworksSyncDispatcherInterface` | `Application/Contracts/Linnworks/` | `dispatchStockItemSync`, `dispatchFullStockItemsSync` |
| `MixpanelSyncDispatcherInterface` | `Application/Contracts/Mixpanel/` | `dispatchCampaignLookupSync` |
| `ContactFormDispatcherInterface` | `Application/Contracts/ContactSubmission/` | `dispatchContactSubmissionProcessing` |
| `InventoryDispatcherInterface` | `Application/Contracts/Inventory/` | `dispatchSkuUpdate` |

**Naming convention**: `{Domain}SyncDispatcherInterface` / `{Domain}DispatcherInterface` — no "Job" in the name. Application shouldn't know it's dispatching to a queue.

### 5 Dispatcher Implementations (in `Infrastructure/{Domain}/Dispatchers/`)

| Implementation | Location | Implements |
|---------------|----------|------------|
| `QueuedShopwiredSyncDispatcher` | `Infrastructure/Shopwired/Dispatchers/` | `ShopwiredSyncDispatcherInterface` |
| `QueuedLinnworksSyncDispatcher` | `Infrastructure/Linnworks/Dispatchers/` | `LinnworksSyncDispatcherInterface` |
| `QueuedMixpanelSyncDispatcher` | `Infrastructure/Mixpanel/Dispatchers/` | `MixpanelSyncDispatcherInterface` |
| `QueuedContactFormDispatcher` | `Infrastructure/HelpScout/Dispatchers/` | `ContactFormDispatcherInterface` |
| `QueuedInventoryDispatcher` | `Infrastructure/Linnworks/Dispatchers/` | `InventoryDispatcherInterface` |

Each implementation simply calls `JobClass::dispatch(...)` on the appropriate job. Dispatchers live in their domain's Infrastructure directory (not inside Jobs/) to follow existing patterns and avoid git mv conflicts.

### Service Provider Bindings

Add interface → implementation bindings to existing domain service providers:
- `app/Providers/LinnworksServiceProvider.php`
- `app/Providers/ShopwiredServiceProvider.php`
- `app/Providers/MixpanelServiceProvider.php`
- `app/Providers/ContactSubmissionServiceProvider.php`
- `app/Providers/InventoryServiceProvider.php`

---

## Commit 2: Refactor UseCases to Use Dispatchers

Replace direct `JobClass::dispatch()` calls with injected dispatcher interfaces.

### Files to modify (15 UseCases):

| UseCase | Current Dispatch | New Dispatcher |
|---------|-----------------|----------------|
| `Application/Linnworks/UseCases/SyncStockItemWithCursorUseCase.php` | `SyncStockItemJob::dispatch()`, `SyncLinnworksStockItemsJob::dispatch()` | `LinnworksSyncDispatcherInterface` |
| `Application/Mixpanel/UseCases/ProcessCampaignLookupSyncUseCase.php` | `SyncCampaignLookupTableJob::dispatch()` | `MixpanelSyncDispatcherInterface` |
| `Application/ContactSubmission/UseCases/SubmitContactFormUseCase.php` | `ProcessContactSubmissionJob::dispatch()` | `ContactFormDispatcherInterface` |
| `Application/Inventory/UseCases/ProcessSkuUpdatesUseCase.php` | `UpdateSkuJob::dispatch()` | `InventoryDispatcherInterface` |
| `Application/Shopwired/UseCases/BackfillShopwiredOrdersUseCase.php` | `SyncShopwiredOrdersRangeJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |
| `Application/Shopwired/UseCases/UpdateProductFreeDeliveryUseCase.php` | `SetProductFreeDeliveryJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |
| `Application/Shopwired/UseCases/Webhooks/UpdateOrderStatusUseCase.php` | `SyncShopwiredOrderJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |
| `Application/Shopwired/UseCases/Webhooks/DeleteOrderRefundUseCase.php` | `SyncShopwiredOrderJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |
| `Application/Shopwired/UseCases/Webhooks/SyncCustomerUseCase.php` | `SyncShopwiredCustomerJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |
| `Application/Shopwired/UseCases/Webhooks/UpdateProductStockUseCase.php` | `SyncShopwiredProductJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |
| `Application/Shopwired/UseCases/Webhooks/SyncBrandUseCase.php` | `SyncShopwiredBrandJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |
| `Application/Shopwired/UseCases/Webhooks/CreateOrderRefundUseCase.php` | `SyncShopwiredOrderJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |
| `Application/Shopwired/UseCases/Webhooks/SyncOrderUseCase.php` | `SyncShopwiredOrderJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |
| `Application/Shopwired/UseCases/Webhooks/SyncCategoryUseCase.php` | `SyncShopwiredCategoryJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |
| `Application/Shopwired/UseCases/Webhooks/SyncProductUseCase.php` | `SyncShopwiredProductJob::dispatch()` | `ShopwiredSyncDispatcherInterface` |

**Pattern**: Replace `use App\Application\Jobs\X\YJob;` import + `YJob::dispatch(...)` with constructor-injected dispatcher interface call.

After this commit, NO Application code references `App\Application\Jobs\*` directly.

### UseCase tests that need updating (4 files):

These tests assert job dispatch behavior (e.g., `Queue::assertPushed(SomeJob::class)`) and must be updated to mock the dispatcher interface instead:
- `tests/Unit/Application/Shopwired/UseCases/Webhooks/SyncProductUseCaseTest.php`
- `tests/Unit/Application/Shopwired/UseCases/Webhooks/SyncBrandUseCaseTest.php`
- `tests/Unit/Application/Shopwired/UseCases/Webhooks/SyncCategoryUseCaseTest.php`
- `tests/Integration/ContactSubmission/ContactFormEndToEndTest.php`

---

## Commit 3: Move Jobs + Update All References + Update Linting

### 3a. Move files (use `git mv` for history preservation)

```
git mv app/Application/Jobs/ app/Infrastructure/Jobs/
```

Update all namespaces in moved files: `App\Application\Jobs\` → `App\Infrastructure\Jobs\`

### 3b. Update all import references across the codebase

Files referencing `App\Application\Jobs\*` (after Commit 2, only these remain):
- **Schedule Service Providers** (8 files in `app/Providers/Schedule/`)
- **Regular Service Providers** (3 files: `BingAdsServiceProvider`, `GoogleAdsServiceProvider`, `MixpanelServiceProvider`)
- **Dispatcher implementations** (5 files in `app/Infrastructure/{Domain}/Dispatchers/`)
- **Job-to-job dispatches** (e.g., `CleanupStaleContactActionsJob` → `ProcessContactSubmissionJob`)
- **Job test files** (9 files in `tests/Unit/Application/Jobs/` and `tests/Feature/Application/Jobs/`)

### 3c. Move test files

```
git mv tests/Unit/Application/Jobs/ tests/Unit/Infrastructure/Jobs/
git mv tests/Feature/Application/Jobs/ tests/Feature/Infrastructure/Jobs/
```

Update test namespaces accordingly.

### 3d. Update architecture rules

#### `deptrac.yaml`
- **Remove** the `ApplicationJobs` layer definition (lines 25-30)
- **Remove** `ApplicationJobs` from Application's allowed dependencies (line 176)
- **Remove** the `ApplicationJobs` ruleset (lines 181-189)
- Jobs are now just part of the `Infrastructure` layer (already defined, no changes needed)

#### `phparkitect.php`
- **Remove** Rule 2b entirely (lines 170-202) — jobs no longer need special carve-out
- **Remove** `*Job` from Application naming whitelist (line 392)
- **Remove** `App\Application\Jobs\Enums` from Application naming exclusions (line 387)
- **Add** Infrastructure Jobs naming rule:
  ```php
  Rule::allClasses()
      ->that(new ResideInOneOfTheseNamespaces('App\Infrastructure\Jobs'))
      ->andThat(new NotResideInTheseNamespaces('App\Infrastructure\Jobs\Enums'))
      ->should(new MatchOneOfTheseNames(['*Job']))
      ->because('Infrastructure Jobs must end with Job suffix.');
  ```
- **Update** architecture diagram in comments (line 44-56) to show Jobs in Infrastructure

#### `phpstan.neon`
- Change path `app/Application/Jobs/*` → `app/Infrastructure/Jobs/*` (line 160)

#### Custom PHPStan Rules (6 files in `app/DevTools/PHPStan/Rules/Jobs/`)
- Find/replace `App\\Application\\Jobs\\` → `App\\Infrastructure\\Jobs\\` in all 6 rule files:
  - `JobMustImplementShouldQueueRule.php`
  - `JobNamingPrefixRule.php`
  - `JobRequiredPropertiesRule.php`
  - `JobRequiredMethodsRule.php`
  - `JobHandleMustCatchThrowableRule.php`
  - `JobMustCallOnQueueRule.php`

---

## Commit 4: Update Documentation

### `CLAUDE.md` (root)
- Update "Application Jobs" references in Clean Architecture section
- Update layer descriptions: Jobs are now Infrastructure delivery mechanisms
- Remove `ApplicationJobs` sub-layer mention
- Update the architecture diagram

### `app/Application/CLAUDE.md`
- Remove the Jobs section (lines 25-46)
- Remove Jobs/Enums from directory structure

### Create `app/Infrastructure/Jobs/CLAUDE.md`
- Move job documentation here (queue tiers, required properties, naming convention)
- Note that jobs are delivery mechanisms calling Application UseCases

---

## Deployment Strategy

**Drain queue before deploying** to avoid `ClassNotFoundException` on serialized jobs with old FQCNs:

1. Pause the scheduler (disable cron or set maintenance mode)
2. Let queue workers finish processing current jobs (monitor via Horizon dashboard)
3. Verify queues are empty
4. Deploy the code changes
5. Resume scheduler and workers

**Why**: Laravel serializes job FQCNs into Redis. Queued jobs referencing `App\Application\Jobs\*` will fail after the namespace changes to `App\Infrastructure\Jobs\*`.

---

## Verification

After all commits:

```bash
make fix          # Auto-fix code style
make lint         # Pint + PHPStan + PHPArkitect + Deptrac
make test         # Full test suite
make deptrac      # Verify no layer violations
```

### Manual verification:
- Confirm no `App\Application\Jobs` references remain (grep across codebase)
- Confirm Deptrac shows no `ApplicationJobs` layer
- Confirm PHPArkitect Rule 2b is gone
- Confirm all 6 custom PHPStan rules detect jobs at new path

---

## Key Files Reference

| Category | Files |
|----------|-------|
| **Architecture configs** | `deptrac.yaml`, `phparkitect.php`, `phpstan.neon` |
| **Custom PHPStan rules** | `app/DevTools/PHPStan/Rules/Jobs/*.php` (6 files) |
| **Schedule providers** | `app/Providers/Schedule/*.php` (8 files) |
| **Service providers (bindings)** | `app/Providers/{Domain}ServiceProvider.php` |
| **Regular service providers** | `app/Providers/BingAdsServiceProvider.php`, `GoogleAdsServiceProvider.php`, `MixpanelServiceProvider.php` |
| **Job files** | `app/Application/Jobs/**/*.php` → `app/Infrastructure/Jobs/**/*.php` |
| **Job test files** | `tests/Unit/Application/Jobs/`, `tests/Feature/Application/Jobs/` |
| **UseCase tests with job refs** | 3 Shopwired webhook UseCase tests + 1 integration ContactForm test |
| **Documentation** | `CLAUDE.md`, `app/Application/CLAUDE.md` |

## Resolved Decisions

1. **Jobs/Enums/QueueName.php** — Only used by job classes. Moves with them to `Infrastructure/Jobs/Enums/`.
2. **Dispatcher interface method signatures** — Will be derived from reading each UseCase's `::dispatch()` call during implementation.
3. **Service providers** — All 5 binding providers exist: `LinnworksServiceProvider`, `ShopwiredServiceProvider`, `MixpanelServiceProvider`, `ContactSubmissionServiceProvider`, `InventoryServiceProvider`.
4. **Singular webhook jobs** — 5 additional Shopwired jobs (`SyncShopwiredOrderJob`, `SyncShopwiredProductJob`, `SyncShopwiredCustomerJob`, `SyncShopwiredBrandJob`, `SyncShopwiredCategoryJob`) extend `AbstractSyncShopwiredEntityJob`. All move together.
5. **Dispatcher location** — `Infrastructure/{Domain}/Dispatchers/` (not inside Jobs/) — follows existing domain-organized pattern and avoids git mv conflict.
6. **Deployment** — Drain queue before deploying to avoid ClassNotFoundException on serialized jobs with old FQCNs.
7. **UseCase test updates** — 4 tests reference job classes for dispatch assertions. Must be updated in Commit 2 to mock dispatcher interfaces instead.
