# Plan: Product Inventory Update Endpoint

## Context

The frontend needs to update JIT and MinimumLevel on individual Linnworks inventory items. These fields control drop-ship behaviour and reorder thresholds respectively. The Linnworks write infrastructure (`InventoryFieldUpdateClient`, `InventoryFieldUpdate` value objects) already exists — this feature exposes it via a consumer API endpoint.

Design decisions resolved via grill-me session + /check review.

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Fields | JIT (bool) + MinimumLevel (int). BinRack deferred. | BinRack column doesn't exist on stock_items |
| Route | `PUT /api/products/inventory` | Body-SKU pattern — matches cost-prices; avoids URL-encoding issues with slashes/spaces in SKUs |
| Controller | `ProductInventoryUpdateController` | Follows `ProductPricingUpdateController` naming |
| Request shape | Items array: `{ items: [{ sku, jit?, minimum_level? }] }` | Matches `UpdateCostPricesRequestDTO` pattern; Max(1) for V1, relaxable to bulk later |
| Per-item validation | `required_without_all` in `rules()` | At least one field per item; new pattern in codebase |
| SKU validation | Linnworks validates existence (no local pre-check) | |
| Sync model | Synchronous (no job dispatch) | |
| Local write-back | Update `linnworks.stock_items` after Linnworks success; log warning if 0 rows | |
| Column mapping | Repository owns enum→column translation | Matches codebase convention (domain→model mapping in Infrastructure) |
| Auth | Standard Supabase JWT + approval gate | |
| Response | 204 No Content | |

## Implementation

### Step 1: Repository interface + implementation

**Modify** `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php`
- Add `updateInventoryFieldsBySku(Sku $sku, InventoryFieldUpdate ...$updates): int`
- `@throws` DatabaseOperationFailedException, DuplicateRecordException, ExternalServiceUnavailableException

**Modify** `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php`
- Implement `updateInventoryFieldsBySku()`:
  - Private `columnFor(InventoryUpdatableField): string` maps enum → DB column name
  - Private `dbValue(InventoryFieldUpdate): mixed` deserializes API-string back to DB-native type (JIT `'true'`→`true`, MinimumLevel `'5'`→`5`)
  - Call `$this->eloquentGateway->updateWhere(StockItemModel::class, 'item_number', $sku->value, $columns)`
- Returns affected row count

### Step 2: Request DTOs

**Create** `app/Presentation/Http/Api/DTOs/UpdateInventoryRequestDTO.php`
- `final class extends Data`
- Property: `public readonly DataCollection $items` with `#[Min(1), Max(1), DataCollectionOf(UpdateInventoryItemDTO::class)]`
- Reference: `UpdateCostPricesRequestDTO` for items-array pattern

**Create** `app/Presentation/Http/Api/DTOs/UpdateInventoryItemDTO.php`
- `final class extends Data`
- Properties: `public readonly string $sku`, `public readonly ?bool $jit = null`, `public readonly ?int $minimum_level = null`
- `rules()`: `'jit' => ['required_without_all:minimum_level', 'boolean']`, `'minimum_level' => ['required_without_all:jit', 'integer', 'min:0']`, `'sku' => ['required', 'string', 'min:1']`
- `toInventoryFieldUpdates(): list<InventoryFieldUpdate>` — builds domain VOs from non-null props

### Step 3: Use case

**Create** `app/Application/Inventory/UseCases/UpdateVariationInventoryUseCase.php`
- `final readonly class`
- Dependencies: `InventoryFieldUpdateClientInterface`, `StockItemRepositoryInterface`, `LoggerInterface`
- `execute(Sku $sku, InventoryFieldUpdate ...$updates): void`
- Flow:
  1. Call `$this->fieldUpdateClient->updateFields($sku, ...$updates)`
  2. Call `$this->stockItemRepository->updateInventoryFieldsBySku($sku, ...$updates)`
  3. If 0 rows affected, `$this->logger->warning(...)` with SKU context

### Step 4: Controller

**Create** `app/Presentation/Http/Api/Controllers/ProductInventoryUpdateController.php`
- `final readonly class`
- Dependency: `UpdateVariationInventoryUseCase`
- `update(UpdateInventoryRequestDTO $data): JsonResponse`
- Extract first item: `$item = $data->items[0]`
- Build `Sku::fromString($item->sku)`, `$item->toInventoryFieldUpdates()`
- Call use case
- Return `new JsonResponse(null, Response::HTTP_NO_CONTENT)`
- Full `@throws` on class + method docblocks

### Step 5: Route registration

**Modify** `routes/api.php`
- Add after line 162 (after `products/cost-prices`): `Route::put('products/inventory', [ProductInventoryUpdateController::class, 'update']);`

### Step 6: Tests

**Create** `tests/Unit/Application/Inventory/UseCases/UpdateVariationInventoryUseCaseTest.php`
- Happy path: client + repo called, no warning
- Zero affected rows: warning logged
- Client exception: bubbles, repo not called

**Create** `tests/Feature/Presentation/Http/Api/Controllers/ProductInventoryUpdateControllerTest.php`
- 401 without auth
- 204 with valid JIT-only, MinLevel-only, both
- 422 with empty body, empty items array, invalid types, negative minimum_level, missing sku, both jit and minimum_level absent
- 404 when Linnworks SKU not found (ResourceNotFoundException)

## Existing code to reuse

| What | Where |
|------|-------|
| `InventoryFieldUpdate::jit(bool)` | `app/Domain/Inventory/ValueObjects/InventoryFieldUpdate.php` |
| `InventoryFieldUpdate::minimumLevel(int)` | same |
| `InventoryFieldUpdateClientInterface` | `app/Application/Contracts/Linnworks/InventoryFieldUpdateClientInterface.php` |
| `InventoryFieldUpdateClient` | `app/Infrastructure/Linnworks/Clients/InventoryFieldUpdateClient.php` |
| `EloquentGateway::updateWhere()` | `app/Infrastructure/Persistence/EloquentGateway.php:725` |
| `Sku::fromString()` | `app/Domain/Catalog/Product/ValueObjects/Sku.php:36` |
| `UpdateCostPricesRequestDTO` | Reference for items-array DTO pattern (`app/Presentation/Http/Api/DTOs/UpdateCostPricesRequestDTO.php`) |
| Exception mapping | `app/Presentation/Http/Api/InternalApiExceptionMapper.php` (all exceptions already mapped) |

## No new Domain or Infrastructure client files needed

Domain VOs and Infrastructure clients already exist. No migrations needed — `jit` and `minimum_level` columns already exist on `linnworks.stock_items`.

## Verification

1. `make lint` — all @throws complete, no type errors
2. `make test` — new tests pass
3. `php artisan route:list --path=products/inventory` — route registered
4. Smoke test: `curl -X PUT -H "X-Local-Bypass: $API_BYPASS_SECRET" "http://127.0.0.1:${API_PORT:-8000}/api/products/inventory" -H "Content-Type: application/json" -d '{"items": [{"sku": "TEST-SKU", "jit": true}]}'`
