# Implementation Log: #465 — Sync JIT flag from Linnworks stock items

## Issue Context
The JIT (Just In Time) flag identifies drop-shipped items not held in stock. Linnworks reorder reports filter these out to avoid suggesting purchase orders for drop-ship items. JIT is returned in `StockLevels[0].JIT` (boolean) from the `GetStockItemsFull` API — it flows through the existing REST sync pipeline with no separate job needed.

Data flow: `Linnworks API → StockLevelResponse → StockItemFullResponse → StockItemFull → StockItemModelMapper → StockItemModel → linnworks.stock_items`

## Implementation

### Migration
- Created `database/migrations/2026_04_02_100000_add_jit_to_linnworks_stock_items.php`
- Adds `boolean('jit')->default(false)` to `linnworks.stock_items`

### StockLevelResponse
- Added `public readonly bool $jit` to constructor (no default — Spatie constructs it)
- `PascalCaseMapper` handles `JIT` → `jit` mapping automatically

### StockItemFullResponse
- Added `jit: $defaultStock !== null ? $defaultStock->jit : false` in `toDomain()`

### StockItemFull (Domain VO)
- Added `public bool $jit` after `minimumLevel` (grouped with stock level fields)
- Updated complexity baseline from 23 → 24 lines for `__construct()`

### StockItemModel
- Added `@property bool $jit` to docblock
- Added `'jit' => 'boolean'` to `casts()`
- Decomposed `casts()` by extracting `timestampCasts()` private helper (method was at 20-line limit, adding jit pushed to 21)

### StockItemModelMapper
- `fromModel()`: added `jit: $model->jit` (PHPStan confirms bool type from docblock, no cast needed)
- `toModelAttributes()`: added `'jit' => $stockItem->jit` after `minimum_level`

### Tests updated
- `StockItemFullTest.php`: added `'jit' => false` to `$defaults` array
- `StockItemParamsBuilderServiceTest.php`: added `jit: false` after `minimumLevel:` in `createTemplate()`
- `GenerateVariantSkusUseCaseTest.php`: added `jit: false` after `minimumLevel:` in `createTemplate()`

## Test Results
- `make test-quick`: 1401 tests passed (2535 assertions) in 7.33s

## Lint Results
- Pint: pass
- PHPStan: pass (after baseline update + useless cast fix + casts() decomposition)
- PHPArkitect: no violations
- Deptrac: no violations
- TLint: LGTM

## Handoff Notes
- All changes are uncommitted
- Migration has been applied locally
- SyncLinnworksStockItemsJob dispatched locally — verify with `SELECT item_number, jit FROM linnworks.stock_items WHERE jit = true LIMIT 10` after job completes
