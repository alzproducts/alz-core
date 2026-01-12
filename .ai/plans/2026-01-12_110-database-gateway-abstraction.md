# Implementation Plan: Database Gateway Abstraction (Option B)

## Summary

Replace `DatabaseClientInterface` + `SupabaseClient` with a proper Clean Architecture database gateway abstraction. This eliminates the semantic naming issue and removes Laravel dependencies from the Application layer.

---

## Interface Design

### Application Layer Interface
```php
// app/Application/Contracts/DatabaseGatewayInterface.php
interface DatabaseGatewayInterface
{
    /**
     * Execute a read operation with exception translation.
     * Use for SELECT queries, existence checks, counts.
     *
     * @template T
     * @param-immediately-invoked-callable $operation
     * @param Closure(): T $operation
     * @return T
     * @throws ExternalServiceUnavailableException Database connection issues
     * @throws DatabaseOperationFailedException Query syntax/schema errors
     */
    public function query(Closure $operation): mixed;

    /**
     * Execute a write operation within a transaction.
     * Use for INSERT, UPDATE, DELETE, or multi-step operations.
     *
     * @template T
     * @param-immediately-invoked-callable $operation
     * @param Closure(): T $operation
     * @param int $attempts Retry attempts on deadlock (1 = no retry)
     * @return T
     * @throws ExternalServiceUnavailableException Database connection issues
     * @throws DuplicateRecordException Unique constraint violation
     * @throws DatabaseOperationFailedException Query syntax/schema errors
     */
    public function transact(Closure $operation, int $attempts = 1): mixed;
}
```

### Infrastructure Implementation
```php
// app/Infrastructure/Database/DatabaseGateway.php
final readonly class DatabaseGateway implements DatabaseGatewayInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private DatabaseManager $db,
    ) {}

    public function query(Closure $operation): mixed { /* ... */ }
    public function transact(Closure $operation, int $attempts = 1): mixed { /* ... */ }

    /**
     * Expose connection for query-builder repositories.
     * NOTE: On concrete class only, not interface.
     */
    public function connection(): ConnectionInterface
    {
        return $this->db->connection();
    }
}
```

---

## Method Naming Rationale

| Method | Purpose | When to Use |
|--------|---------|-------------|
| `query()` | Exception translation only | READ operations (SELECT, EXISTS, COUNT) |
| `transact()` | Transaction + exception translation | WRITE operations (INSERT, UPDATE, DELETE) |

**Why writes should always use `transact()`:**
- Single writes are atomic at DB level, but wrapping ensures consistent exception handling
- Pattern is explicit: `query()` = reading, `transact()` = writing
- Negligible overhead for single-statement transactions

**Edge case - `saveMany()` pattern:**
- Each `save()` calls `transact()` individually
- The loop itself doesn't wrap in transaction (intentional partial success)

---

## Repository Usage Patterns

### Eloquent Repositories (like EloquentOrderRepository)
```php
public function __construct(
    private DatabaseGatewayInterface $gateway,  // Interface injection
) {}

public function save(Order $entity): void
{
    $this->gateway->transact(function () use ($entity): void {
        $model = OrderModel::updateOrCreate([...]);
        $this->syncProducts($model, $entity);
    });
}

public function getByExternalId(int $id): Order
{
    return $this->gateway->query(function () use ($id): Order {
        $model = OrderModel::query()->where('external_id', $id)->first();
        // ...
    });
}
```

### Query-Builder Repositories (like EscalationsConfigRepository)
```php
public function __construct(
    private DatabaseGateway $gateway,  // Concrete class injection (needs connection())
) {}

public function get(): EscalationsConfig
{
    return $this->gateway->query(
        fn() => $this->gateway->connection()->table(self::TABLE)->first()
    );
}
```

---

## File Changes

### CREATE (3 files)
| File | Purpose |
|------|---------|
| `app/Application/Contracts/DatabaseGatewayInterface.php` | New interface (include `@param-immediately-invoked-callable`) |
| `app/Infrastructure/Database/DatabaseGateway.php` | Implementation (SERVICE_NAME = 'Database') |
| `app/Providers/DatabaseServiceProvider.php` | DI bindings (implements DeferrableProvider) |

### MODIFY (5 files)
| File | Changes |
|------|---------|
| `app/Infrastructure/Shopwired/Repositories/AbstractShopwiredEloquentRepository.php` | `DatabaseClientInterface` → `DatabaseGatewayInterface`, `execute()` → `query()` |
| `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php` | `executeTransaction()` → `transact()` (inherits gateway from parent) |
| `bootstrap/providers.php` | Line 36: Replace `SupabaseServiceProvider` with `DatabaseServiceProvider` |
| `phpstan.neon` | Update line 167: `SupabaseClient.php` → `DatabaseGateway.php` path |
| `deptrac.yaml` | No changes needed (uses wildcard `^App\\Infrastructure\\.*`) |

### MOVE (3 files)
| From | To |
|------|-----|
| `app/Infrastructure/Supabase/EscalationsConfigRepository.php` | `app/Infrastructure/CustomerService/EscalationsConfigRepository.php` |
| `tests/Integration/Infrastructure/Supabase/SupabaseClientTest.php` | `tests/Integration/Infrastructure/Database/DatabaseGatewayTest.php` |
| `tests/Unit/Infrastructure/Supabase/EscalationsConfigRepositoryTest.php` | `tests/Unit/Infrastructure/CustomerService/EscalationsConfigRepositoryTest.php` |

### DELETE (3 files)
| File | Reason |
|------|--------|
| `app/Application/Contracts/DatabaseClientInterface.php` | Replaced by DatabaseGatewayInterface |
| `app/Infrastructure/Supabase/SupabaseClient.php` | Replaced by DatabaseGateway |
| `app/Providers/SupabaseServiceProvider.php` | Merged into DatabaseServiceProvider |

---

## Implementation Order

### Step 1: Create New Abstraction
1. Create `DatabaseGatewayInterface` in Application/Contracts
2. Create `DatabaseGateway` in Infrastructure/Database (copy exception logic, SERVICE_NAME = 'Database')
3. Create `DatabaseServiceProvider` with bindings (implements DeferrableProvider)

### Step 2: Migrate Abstract Repository (Critical - Do First)
4. Update `AbstractShopwiredEloquentRepository` - this is the parent class
   - `DatabaseClientInterface` → `DatabaseGatewayInterface`
   - `execute()` → `query()`

### Step 3: Migrate Concrete Repositories
5. Update `EloquentOrderRepository` - inherits from abstract, just update `executeTransaction()` → `transact()`
6. Move `EscalationsConfigRepository` to Infrastructure/CustomerService
   - Update namespace
   - `DatabaseClientInterface` → `DatabaseGateway` (concrete, needs connection())
   - `execute()` → `query()`
   - Remove `ConnectionInterface` injection (use `$gateway->connection()`)

### Step 4: Update Configuration
7. Update `bootstrap/providers.php` line 36: `SupabaseServiceProvider` → `DatabaseServiceProvider`
8. Update `phpstan.neon` line 167: path to DatabaseGateway.php

### Step 5: Migrate Tests
9. Move `SupabaseClientTest` → `tests/Integration/Infrastructure/Database/DatabaseGatewayTest.php`
10. Move `EscalationsConfigRepositoryTest` → `tests/Unit/Infrastructure/CustomerService/`
11. Update test namespaces and mock types

### Step 6: Cleanup
12. Delete old files (DatabaseClientInterface, SupabaseClient, SupabaseServiceProvider)
13. Delete empty `Infrastructure/Supabase/` directory
14. Run `make lint && make test` to verify

---

## Verification

```bash
# All must pass
make lint      # Pint, PHPStan, PHPArkitect, Deptrac
make test      # Unit + Integration tests

# Manual verification - all should return nothing in app/
grep -r "DatabaseClientInterface" app/
grep -r "SupabaseClient" app/
grep -r "Infrastructure/Supabase" app/

# Check no references remain in tests (except git history)
grep -r "DatabaseClientInterface" tests/
grep -r "SupabaseClient" tests/

# Verify new structure exists
ls app/Infrastructure/Database/
ls app/Infrastructure/CustomerService/
ls tests/Unit/Infrastructure/CustomerService/
ls tests/Integration/Infrastructure/Database/
```

---

## Architecture Diagram

```
┌─────────────────────────────────────────────────────────────┐
│ Application Layer                                           │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Contracts/                                              │ │
│ │ ├── DatabaseGatewayInterface.php  ← query(), transact() │ │
│ │ ├── OrderRepositoryInterface.php                        │ │
│ │ └── EscalationsConfigRepositoryInterface.php            │ │
│ └─────────────────────────────────────────────────────────┘ │
└──────────────────────────┬──────────────────────────────────┘
                           │ implements
                           ↓
┌─────────────────────────────────────────────────────────────┐
│ Infrastructure Layer                                        │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Database/                                               │ │
│ │ └── DatabaseGateway.php  ← implements interface         │ │
│ │     └── connection()     ← concrete-only method         │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ (DatabaseServiceProvider.php lives in app/Providers/)       │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ Shopwired/Repositories/                                 │ │
│ │ ├── AbstractShopwiredEloquentRepository.php (abstract)  │ │
│ │ │   └── injects: DatabaseGatewayInterface               │ │
│ │ └── EloquentOrderRepository.php (extends abstract)      │ │
│ │     └── inherits gateway from parent                    │ │
│ └─────────────────────────────────────────────────────────┘ │
│                                                             │
│ ┌─────────────────────────────────────────────────────────┐ │
│ │ CustomerService/                                        │ │
│ │ └── EscalationsConfigRepository.php                     │ │
│ │     └── injects: DatabaseGateway (concrete, for conn)   │ │
│ └─────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────┘
```

---

## Notes

- **All at once migration**: No dual patterns or deprecation period
- **Eloquent repos inject interface**: `DatabaseGatewayInterface` (don't need connection())
- **Query-builder repos inject concrete**: `DatabaseGateway` (need connection())
- **No Laravel in Application layer**: Interface only uses `Closure`, no Laravel types

---

## Decisions Confirmed

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Query access pattern | `connection()` on concrete class only | Extensible - can extract interface later if query-builder repos grow |
| Method naming | `query()` / `transact()` | Explicit intent - writes should always be transactional |
| Folder structure | Delete `Supabase/`, create `Database/`, group repos by domain | Nothing Supabase-specific exists |
| SERVICE_NAME constant | `'Database'` | Generic, not tied to Supabase branding |

---

## Review Corrections Applied

Issues found during critical reviews and fixed in this plan:

### Review 1
| Severity | Issue | Resolution |
|----------|-------|------------|
| CRITICAL | Missing `AbstractShopwiredEloquentRepository` | Added to MODIFY list, added Step 2 in implementation order |
| HIGH | Wrong service provider DELETE path | Corrected to `app/Providers/SupabaseServiceProvider.php` |
| HIGH | Missing unit test | Added `EscalationsConfigRepositoryTest.php` to MOVE list |
| HIGH | phpstan.neon outdated | Added to MODIFY list with specific line reference |
| MEDIUM | SERVICE_NAME constant | Decision: change to `'Database'` |
| MEDIUM | Inheritance chain | Clarified in implementation order (Step 2 before Step 3) |

### Review 2
| Severity | Issue | Resolution |
|----------|-------|------------|
| HIGH | Service provider CREATE location | Corrected to `app/Providers/DatabaseServiceProvider.php` |
| HIGH | Wrong provider registration file | Corrected to `bootstrap/providers.php` (not config/app.php) |
| MEDIUM | Missing `@param-immediately-invoked-callable` | Added to interface design |
| LOW | deptrac.yaml "update if needed" | Clarified: no changes needed (wildcard pattern) |
