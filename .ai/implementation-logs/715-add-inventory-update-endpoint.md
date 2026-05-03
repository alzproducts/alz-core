# Implementation Log — #715: PUT /api/products/inventory

## Status
Complete — implementation, simplify, sweep done; live curl validation skipped (write-only endpoint mutates Linnworks data); covered by 12 controller feature tests + 3 use case unit tests, all passing

## Validation
- Live curl against `PUT /api/products/inventory` deliberately not executed — endpoint mutates Linnworks production-shared inventory state
- Behavior coverage: 12 controller feature tests (auth, all 422 paths, all 204 paths, 404), 3 use case unit tests (happy path, zero-rows warning, client exception bubble)
- All 3352 tests passing; lint clean (Pint, PHPStan, PHPArkitect, Deptrac, TLint)

## Simplify Changes
- Created `UpdateInventoryFieldsCommand` domain command (`app/Domain/Inventory/Commands/`)
- Renamed `toInventoryFieldUpdates()` → `toCommand()` on `UpdateInventoryItemDTO` per project rules; moved `Sku::fromString()` inside it
- Updated `UpdateVariationInventoryUseCase::execute()` to accept `UpdateInventoryFieldsCommand`
- Simplified controller to single `$item->toCommand()` call
- Consolidated `columnFor()` + `dbValue()` into single `fieldMapping()` method in repository (one exhaustive match instead of two)

## Plan Reference
`.ai/plans/2026-05-03_715-product-inventory-update-endpoint.md`

## Decisions
- Repository method: `updateInventoryFieldsBySku(Sku $sku, InventoryFieldUpdate ...$updates): int`
- Column mapping: `JIT` → `jit`, `MinimumLevel` → `minimum_level` (enum cases only — BinRack deferred)
- DB value deserialization: JIT `'true'`/`'false'` → bool, MinimumLevel string → int
- Use `EloquentGateway::updateWhere()` — already handles `transact()` internally
- `UpdateInventoryItemDTO::toInventoryFieldUpdates()` returns `list<InventoryFieldUpdate>`
- Use case: `UpdateVariationInventoryUseCase::execute(Sku, InventoryFieldUpdate...)` — no return
- Controller: `final readonly`, no try-catch (global exception mapper handles all)

## Files Created
- `app/Application/Contracts/Linnworks/StockItemRepositoryInterface.php` (modified)
- `app/Infrastructure/Linnworks/Repositories/EloquentStockItemRepository.php` (modified)
- `app/Presentation/Http/Api/DTOs/UpdateInventoryRequestDTO.php` (new)
- `app/Presentation/Http/Api/DTOs/UpdateInventoryItemDTO.php` (new)
- `app/Application/Inventory/UseCases/UpdateVariationInventoryUseCase.php` (new)
- `app/Presentation/Http/Api/Controllers/ProductInventoryUpdateController.php` (new)
- `routes/api.php` (modified)
- `tests/Unit/Application/Inventory/UseCases/UpdateVariationInventoryUseCaseTest.php` (new)
- `tests/Feature/Presentation/Http/Api/Controllers/ProductInventoryUpdateControllerTest.php` (new)

## PR Notes
feat(api): add PUT /api/products/inventory endpoint (#715)

Exposes Linnworks JIT and MinimumLevel field updates via a new consumer API endpoint.
Accepts `{ items: [{ sku, jit?, minimum_level? }] }` with at least one field per item.
Updates Linnworks synchronously then writes back to `linnworks.stock_items`. Returns 204 on success.
