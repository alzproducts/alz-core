# Issue: DatabaseClientInterface Naming & Design Refactor

**Created:** 2026-01-12
**Status:** Deferred
**Priority:** Low (cosmetic/semantic improvement)

## Problem Statement

The current `DatabaseClientInterface` and `SupabaseClient` naming is semantically misleading:

1. **"Client" implies direct interaction** — like `HttpClient`, `RedisClient` that actually make external calls
2. **The class is actually an exception boundary** — wraps operations and translates exceptions
3. **The interface doesn't enforce database behavior** — `execute(fn() => "hello")` works fine
4. **Double injection pattern** — Query-builder repositories need both `DatabaseClientInterface` AND `ConnectionInterface`

## Current Implementation

### Interface (Application Layer)
```php
// app/Application/Contracts/DatabaseClientInterface.php
interface DatabaseClientInterface
{
    public function execute(Closure $operation): mixed;
    public function executeTransaction(Closure $operation, int $attempts = 1): mixed;
}
```

### Implementation (Infrastructure Layer)
```php
// app/Infrastructure/Supabase/SupabaseClient.php
final readonly class SupabaseClient implements DatabaseClientInterface
{
    public function __construct(
        private LoggerInterface $logger,
        private DatabaseManager $db,
    ) {}
    // ... exception translation logic
}
```

### Usage Pattern (Double Injection)
```php
// app/Infrastructure/Supabase/EscalationsConfigRepository.php
public function __construct(
    private DatabaseClientInterface $database,   // For exception translation
    private ConnectionInterface $connection,      // For actual queries
) {}

public function get(): EscalationsConfig
{
    $connection = $this->connection;
    return $this->database->execute(
        fn() => $connection->table(...)->first()
    );
}
```

## The Semantic Mismatch

The class doesn't "client" the database — it wraps closures with exception translation:

```php
// What it actually does:
try {
    return $operation();  // Runs ANY closure
} catch (UniqueConstraintViolationException $e) {
    throw new DuplicateRecordException(...);
} catch (LostConnectionException $e) {
    throw new ExternalServiceUnavailableException(...);
}
```

## Proposed Solution

### Option A: Full Rename + Connection Method (Recommended)

**Rename files and classes:**
- `DatabaseClientInterface` → `DatabaseBoundaryInterface`
- `SupabaseClient` → `SupabaseBoundary`

**Add connection() to implementation only:**
```php
// SupabaseBoundary.php
public function connection(): ConnectionInterface
{
    return $this->db->connection();
}
```

**Simplified repository usage:**
```php
public function __construct(
    private SupabaseBoundary $database,  // Single injection
) {}

public function get(): EscalationsConfig
{
    return $this->database->execute(
        fn() => $this->database->connection()->table(...)->first()
    );
}
```

### Option B: Just Add connection()

Keep current names, only add `connection()` method to `SupabaseClient`.

- Pros: Minimal changes, solves double-injection
- Cons: Naming remains misleading

### Option C: Just Rename

Rename to Boundary pattern, keep double-injection in repositories.

- Pros: Fixes semantic issue, no behavior change
- Cons: Doesn't solve double-injection ergonomics

## Why "Boundary"?

- **Clean Architecture term** — boundaries are where translation happens
- **Describes the role** — database exceptions → domain exceptions
- **Accurate** — it guards the boundary between Infrastructure and Domain

## Files Affected (Option A)

1. `app/Application/Contracts/DatabaseClientInterface.php` → `DatabaseBoundaryInterface.php`
2. `app/Infrastructure/Supabase/SupabaseClient.php` → `SupabaseBoundary.php`
3. `app/Providers/SupabaseServiceProvider.php` — update binding
4. `app/Infrastructure/Supabase/EscalationsConfigRepository.php` — simplify constructor
5. `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php` — update import
6. `tests/Integration/Infrastructure/Supabase/SupabaseClientTest.php` → `SupabaseBoundaryTest.php`
7. `phpstan.neon` — update any ignores referencing old names

## Trade-offs

### Adding connection() to concrete class

**Pro:** Single injection point for query-builder repositories
**Con:** Repositories that use it depend on concrete `SupabaseBoundary`, not the interface

**Why this is acceptable:**
- Repositories are Infrastructure layer — they CAN depend on concrete classes
- Only one database implementation exists
- The `connection()` method is implementation-specific anyway
- Eloquent repositories don't need it (they use Models)

### Interface in Application Layer

**Q:** Should the interface even exist if only Infrastructure uses it?
**A:** Yes, because:
- Documents exception translation guarantees
- Enables testing with mock boundary
- PHPStan template types for return values
- Contract for any future database wrapper

## Decision

**Deferred** — Will revisit after completing Issue #108 (ShopWired Order Persistence).

The current design is architecturally sound, just semantically imprecise. Not blocking.

## Related

- `app/Application/Contracts/DatabaseClientInterface.php`
- `app/Infrastructure/Supabase/SupabaseClient.php`
- `app/Infrastructure/Supabase/EscalationsConfigRepository.php`
- `app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php`