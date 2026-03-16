# Plan: Sync Linnworks Supplier Directory

## Context

The Linnworks `GetSuppliers` endpoint returns the **master supplier directory** — a small, flat list of all suppliers with contact/address details. This is distinct from the existing `StockItemSupplier` value object, which represents supplier-to-stock-item *relationships* fetched via `GetStockItemsFull`.

Syncing this directory hourly gives us a first-class `linnworks.suppliers` table for querying, reporting, and future supplier management features.

## Architecture

```
Job → UseCase → InventoryClientInterface::getSuppliers() (Application contract)
                 ├── InventoryClient (Infrastructure — calls GetSuppliers)
                 └── SupplierRepositoryInterface (Application contract)
                      └── EloquentSupplierRepository (Infrastructure — upserts to DB)
```

`getSuppliers()` added to existing `InventoryClientInterface` — the endpoint is `/api/Inventory/GetSuppliers`, so it belongs with the other Inventory endpoints. No separate client needed for a single method.

## Sync Strategy

**Full replace with reconciliation:** Fetch all suppliers → upsert → delete any local records whose `pk_supplier_id` was NOT in the fetched set. Since `GetSuppliers` returns the complete list, this cleanly handles suppliers deleted from Linnworks.

## Rename: `SupplierResponse` → `StockItemSupplierResponse`

Rename the existing `app/Infrastructure/Linnworks/Responses/SupplierResponse.php` to `StockItemSupplierResponse.php` — it maps stock-item-supplier junction data (from `GetStockItemsFull`), not the supplier directory. This frees up `SupplierResponse` for the new DTO (which we'll name simply `SupplierResponse`). Use `git mv` to preserve history. Update all references (imports in `StockItemFullResponse` and anywhere else that uses it).

## Files to Create (11)

### 1. Migration: `database/migrations/{timestamp}_create_linnworks_suppliers_table.php`
- Table: `linnworks.suppliers`
- PK: `uuid('id')` with `HasUuids`
- Unique key: `pk_supplier_id` (Linnworks UUID) — upsert target
- All API fields: `supplier_name`, `contact_name`, `address`, `alternative_address`, `city`, `region`, `country`, `post_code`, `telephone_number`, `secondary_tel_number`, `fax_number`, `email`, `web_page`, `currency`
- `timestampsTz()` for created_at/updated_at
- All fields except `pk_supplier_id` and `supplier_name` are nullable

### 2. Domain VO: `app/Domain/Inventory/ValueObjects/Supplier.php`
- `final readonly class Supplier`
- All 15 fields from API mapped to camelCase
- `Assert::notEmpty()` on `pkSupplierId` and `supplierName`
- Lives alongside existing `StockItemSupplier` (different entity, same bounded context)

### 3. Response DTO: `app/Infrastructure/Linnworks/Responses/SupplierResponse.php` (new file)
- Named `SupplierResponse` (the old one is now `StockItemSupplierResponse`)
- `#[MapInputName(PascalCaseMapper::class)]` with explicit `#[MapInputName('pkSupplierID')]` override for the ID field (lowercase `pk` + uppercase `ID` breaks the mapper)
- Implements `DomainConvertibleInterface` with `toDomain()` → `Supplier`

### 4. Eloquent Model: `app/Infrastructure/Linnworks/Models/SupplierModel.php`
- `final class` with `HasUuids`, table `linnworks.suppliers`
- Implements `EloquentDomainMappableInterface`
- Delegates `toDomain()` to `SupplierMapper::fromModel($this)`

### 5. Mapper: `app/Infrastructure/Linnworks/Mappers/SupplierMapper.php`
- `final class` with static `fromModel()` and `toModelAttributes()` methods
- Straightforward field mapping (no relations, no complex types)
- No manual timestamps needed — `fillForInsert()` handles `created_at`/`updated_at` automatically

### 6. Repository Interface: `app/Application/Contracts/Linnworks/SupplierRepositoryInterface.php`
- `extends RepositoryWriteInterface<Supplier>`
- Add `saveSuppliersBulk(array $suppliers): SaveManyResult` — bulk upsert (same pattern as `CustomerRepositoryInterface::saveCustomersBulk`)
- Add `deleteWhereNotIn(array $pkSupplierIds): int` — reconciliation (delete stale records)

### 7. Repository Implementation: `app/Infrastructure/Linnworks/Repositories/EloquentSupplierRepository.php`
- `extends AbstractEloquentRepository<Supplier>`
- Implements `getModelClass()`, `getEntityIdentifier()`, `entityToAttributes()`, `getUpsertKeys()`
- Upsert key: `['pk_supplier_id']`
- `saveSuppliersBulk()` wraps `$this->saveManyBulk()` with mapper callable internally (same pattern as `EloquentCustomerRepository::saveCustomersBulk`)
- `deleteWhereNotIn()` — deletes records where `pk_supplier_id NOT IN (...)` using `DatabaseGatewayInterface`

### 8. Use Case: `app/Application/Linnworks/UseCases/SyncSuppliersUseCase.php`
- `final readonly class` with constructor injection: `InventoryClientInterface`, `SupplierRepositoryInterface`, `LoggerInterface`
- Flow: fetch all → `saveSuppliersBulk()` → reconcile (delete stale) → return `SyncResult`
- After upsert, calls `deleteWhereNotIn()` with the fetched `pkSupplierIds` to remove stale records
- No pagination, no batching (small dataset)
- Lets exceptions bubble (no try-catch per Application layer pattern)

### 9. Job: `app/Application/Jobs/Linnworks/SyncLinnworksSuppliersJob.php`
- Exact Pattern A from `SyncLinnworksStockItemsJob`:
  - `ShouldBeUnique`, `ShouldQueue`
  - `$tries = 2`, `$maxExceptions = 2`, `$backoff = [60]`
  - `$timeout = 120` (2 min — small dataset)
  - `$uniqueFor = 300` (5 min — just enough to cover execution + retry; scheduling frequency is the scheduler's concern, not the job's)
  - `uniqueId()` = `'sync-linnworks-suppliers'`
  - Queue: `QueueName::Low`
  - 3 catch blocks: `TransientApiFailure` → release/rethrow, `PermanentApiFailure` → fail, `Throwable` → fail
  - `failed()` method with API vs non-API log level distinction

### 10. Test: `tests/Unit/Domain/Inventory/ValueObjects/SupplierTest.php`
- Test construction with valid data
- Test `Assert::notEmpty()` fails on empty `pkSupplierId`
- Test `Assert::notEmpty()` fails on empty `supplierName`
- Test nullable fields accept null

### 11. Test: `tests/Unit/Application/Linnworks/UseCases/SyncSuppliersUseCaseTest.php`
- Test happy path: fetches suppliers, calls `saveSuppliersBulk()`, calls `deleteWhereNotIn()`, returns `SyncResult`
- Test empty response: returns `SyncResult::empty()`, no `deleteWhereNotIn()` call
- Test API exceptions bubble through (no catch in use case)

## Files to Modify (5)

### 12. Rename: `app/Infrastructure/Linnworks/Responses/SupplierResponse.php` → `StockItemSupplierResponse.php`
- `git mv` to preserve history
- Update class name and all imports (check `StockItemFullResponse.php` and any other references)

### 13. `app/Application/Contracts/Linnworks/InventoryClientInterface.php`
- Add `getSuppliers(): array` method returning `list<Supplier>`
- Add `@throws` annotations matching the other methods

### 14. `app/Infrastructure/Linnworks/Clients/InventoryClient.php`
- Implement `getSuppliers()` method
- Calls `$this->transport->post('/api/Inventory/GetSuppliers')` (no params)
- Parses with `self::parseDirectArrayToDomain($response->json(), SupplierResponse::class)`
- **API format note:** If 400 errors occur during testing, try `postFormParams()` or `postJson()` (Linnworks uses inconsistent formats)

### 15. `app/Providers/LinnworksServiceProvider.php`
- Add singleton binding for `SupplierRepositoryInterface` → `new EloquentSupplierRepository(...)` (same pattern as `StockItemRepositoryInterface`)
- Add to `provides()` array

### 16. `app/Providers/Schedule/LinnworksScheduleServiceProvider.php`
- Add hourly schedule:
  ```php
  Schedule::job(new SyncLinnworksSuppliersJob())
      ->name('sync-linnworks-suppliers')
      ->hourly()
      ->onOneServer()
      ->withoutOverlapping(10);
  ```

## Verification

1. **Run migration**: `php artisan migrate`
2. **Tinker test**: `app(InventoryClientInterface::class)->getSuppliers()` — verify API returns data and maps correctly
3. **Dispatch job**: `SyncLinnworksSuppliersJob::dispatch()` — verify `linnworks.suppliers` table populated
4. **Run linters**: `make lint` (PHPStan, Pint, PHPArkitect, Deptrac)
5. **Run tests**: `make test`
6. **Verify schedule**: `php artisan schedule:list` — confirm hourly entry appears

## Implementation Order

1. Rename `SupplierResponse` → `StockItemSupplierResponse` (unblocks new DTO naming)
2. Migration (foundation)
3. Domain VO + VO test (no dependencies)
4. Response DTO `SupplierResponse` (depends on Domain VO)
5. Add `getSuppliers()` to `InventoryClientInterface` + `InventoryClient` (depends on DTO)
6. Eloquent model + mapper (depends on Domain VO)
7. Repository interface + implementation (depends on model)
8. Use case + use case test (depends on client + repository interfaces)
9. Job (depends on use case)
10. Service provider + schedule (wiring)
11. Verify with tinker + linters + tests
