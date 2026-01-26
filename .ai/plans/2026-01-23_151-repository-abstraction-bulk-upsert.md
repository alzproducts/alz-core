# Plan: Repository Abstraction + Bulk Upsert

## Objective
1. Extract duplicated `saveMany()` logic into a shared abstract base class (single change point)
2. Implement bulk upsert pattern to fix ShopWired sync timeouts

## Phase 1: Extract AbstractEloquentRepository

### Problem
`saveMany()` is nearly identical in both abstracts (95% same code), differing only in:
- Identifier type: `int` vs `string`
- Log key: `'external_id'` vs `'linnworks_id'`

### Solution
Create `AbstractEloquentRepository` base class that both vendor-specific abstracts extend.

### New Hierarchy
```
AbstractEloquentRepository (new - has saveMany, saveManyBulk)
├── AbstractLinnworksEloquentRepository extends it (string identifiers)
└── AbstractShopwiredEloquentRepository extends it (int identifiers, adds query methods)
    ├── EloquentOrderRepository
    ├── EloquentProductRepository
    └── ...
```

### Files to Create
| File | Purpose |
|------|---------|
| `app/Infrastructure/Repositories/AbstractEloquentRepository.php` | Base class with `saveMany()`, `saveManyBulk()` |

### Files to Modify
| File | Changes |
|------|---------|
| `app/Infrastructure/Linnworks/Repositories/AbstractLinnworksEloquentRepository.php` | Extend base, remove `saveMany()`, add `getIdentifierLogKey()` |
| `app/Infrastructure/Shopwired/Repositories/AbstractShopwiredEloquentRepository.php` | Extend base, remove `saveMany()`, add `getIdentifierLogKey()` |

### Base Class Design
```php
namespace App\Infrastructure\Repositories;

abstract class AbstractEloquentRepository
{
    public function __construct(
        protected readonly DatabaseGatewayInterface $gateway,
    ) {}

    abstract public function save(object $entity): void;
    abstract protected function getEntityIdentifier(object $entity): int|string;
    abstract protected function getEntityTypeName(): string;
    abstract protected function getIdentifierLogKey(): string;

    public function saveMany(array $entities): SaveManyResult
    {
        // Consolidated implementation using $this->getIdentifierLogKey()
    }
}
```

### Vendor Abstract Changes
```php
// AbstractLinnworksEloquentRepository - extends base AND implements interface
abstract class AbstractLinnworksEloquentRepository extends AbstractEloquentRepository
    implements LinnworksRepositoryInterface
{
    abstract protected function getEntityIdentifier(object $entity): string; // Narrows to string

    protected function getIdentifierLogKey(): string
    {
        return 'linnworks_id';
    }
}

// AbstractShopwiredEloquentRepository - extends base AND implements interface
abstract class AbstractShopwiredEloquentRepository extends AbstractEloquentRepository
    implements ShopwiredRepositoryInterface
{
    abstract protected function getEntityIdentifier(object $entity): int; // Narrows to int

    protected function getIdentifierLogKey(): string
    {
        return 'external_id';
    }

    // Keeps its query methods: getByExternalId(), existsByExternalId()
}
```

### Migration
- **Concrete repositories: NO CHANGES** (all already implement required abstract methods)
- Vendor abstracts extend new base instead of implementing interface directly
- Add `getIdentifierLogKey()` to each vendor abstract

---

## Phase 2: Implement Bulk Upsert in Base Class

### Problem (from handoff)
- 30,000+ orders × ~14 DB operations = 420,000 queries
- 20ms Supabase latency × 420k = ~140 minutes (exceeds 70min timeout)
- `updateOrCreate()` is NOT atomic (race conditions cause constraint errors)

### Solution
Add `saveManyBulk()` method to `AbstractEloquentRepository` using `fillForInsert()` + `upsert()`.

### Two Methods - Different Use Cases

| Method | Use Case | Semantics |
|--------|----------|-----------|
| `saveMany()` | Small batches (10-15 rows), critical data | Per-row error handling, immediate traceability, continue on failure |
| `saveManyBulk()` | Large syncs (30,000+ rows) | Single query per batch, maximum throughput |

**Keep both methods** - `saveMany()` for granular control, `saveManyBulk()` for performance.

### New Method in Base Class
```php
/**
 * Bulk persist entities using database upsert (single query per batch).
 *
 * Uses fallback strategy: bulk upsert first (fast), on failure falls back
 * to individual saves to identify specific bad records. This gives both
 * throughput AND accurate error tracing.
 *
 * @param list<object> $entities
 * @param int<1, max> $batchSize Entities per upsert query (default 500)
 */
public function saveManyBulk(array $entities, int $batchSize = 500): SaveManyResult
{
    // 1. Transform entities to arrays via toModelAttributes()
    // 2. Batch into chunks of $batchSize
    // 3. For each chunk:
    //    a. Try: fillForInsert() + upsert() (fast path)
    //    b. On exception: fall back to individual save() calls to identify failures
    // 4. Return SaveManyResult with accurate succeeded/failed/failedReferences
}
```

### Error Handling Strategy
**Fallback on failure**: Bulk first, individual on error
- Fast path (99%): Bulk upsert succeeds → all counted as succeeded
- Error path: Catch exception → retry batch one-by-one → accurate failure tracking
- Result: Same `SaveManyResult` semantics as `saveMany()`, but faster

### Additional Abstract Methods
```php
abstract protected function getModelClass(): string;  // Already in ShopWired, add to Linnworks
abstract protected function toModelAttributes(object $entity): array;  // NEW - entity → array
abstract protected function getUpsertUniqueBy(): array;  // ['external_id'] or ['stock_item_id']

// OPTIONAL - defaults to null (update all columns)
protected function getUpsertUpdateColumns(): ?array
{
    return null; // Override only if you need to exclude specific columns
}
```

### Concrete Repository Changes
Each repository implements:
- `toModelAttributes()` - convert domain entity to array (most already have mappers)
- `getUpsertUniqueBy()` - return conflict detection column(s)
- `getUpsertUpdateColumns()` - **optional**, only override if excluding columns

### Sync Jobs
Update sync jobs to call `saveManyBulk()` instead of `saveMany()`:
- `SyncShopwiredOrdersJob` - orders + child records
- `SyncShopwiredProductsJob` - products + variations

---

## Phase 3: Fix Database Exception Logging

### Problem
Constraint violations currently logged at INFO level (legacy from when duplicates were expected during `updateOrCreate()` loops).

### Solution
With proper `upsert()`, constraint violations are **unexpected errors** - change to ERROR level.

### File to Modify
`app/Infrastructure/Database/DatabaseGateway.php`

```php
// BEFORE (line 124-125)
$this->logger->info('Unique constraint violation', [

// AFTER
$this->logger->error('Unique constraint violation', [
```

### Rationale
- `upsert()` handles conflicts atomically - no expected duplicates
- Constraint violations indicate bugs or race conditions
- ERROR level ensures proper alerting and traceability

---

## Verification

### After Phase 1 (Abstract Base Class)
```bash
make lint   # PHPStan, Pint, PHPArkitect, Deptrac
make test   # Existing tests pass without changes
```

### After Phase 2 (Bulk Upsert)
```bash
make test                    # All tests pass
php artisan tinker           # Manual test with small batch
# Monitor production sync times (target: <10 minutes vs current 70+ timeout)
```

### After Phase 3 (Logging)
```bash
make lint   # Verify no issues
# Verify in logs: constraint violations now appear at ERROR level
```

---

## Decisions Made

1. **Child records**: Bulk upsert children alongside parent (maximum performance gain)
2. **Rollout**: All phases together in single PR
3. **Keep both methods**: `saveMany()` for small critical batches, `saveManyBulk()` for large syncs
4. **`getUpsertUpdateColumns()`**: Optional with null default (update all columns)
5. **Logging levels**: Change constraint violations from INFO → ERROR

---

## Risk Assessment

| Risk | Mitigation |
|------|------------|
| Eloquent observers bypassed by `upsert()` | Verified: No observers on ShopWired models |
| Cast handling in `upsert()` | `fillForInsert()` applies casts before upsert |
| Transaction size limits | Batch size 500 is safe; configurable |
| Rollback complexity | Phase 1 is pure refactor (no behavior change) |
