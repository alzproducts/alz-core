# Feasibility Analysis: API Exception Base Types Refactoring

## Executive Summary

**Verdict: HIGHLY FEASIBLE** — Refactor to two abstract base types (`PermanentApiFailure`, `TransientApiFailure`) while keeping all 7 concrete exception classes for semantic clarity.

## Current State

### Exception Hierarchy
```
AbstractApiException (abstract marker)
├── AuthenticationExpiredException (permanent)
├── InvalidApiResponseException (permanent)
├── InvalidApiRequestException (permanent)
├── PayloadSerializationException (permanent)
├── ResourceNotFoundException (permanent)
├── UnexpectedApiResultException (permanent)
└── ExternalServiceUnavailableException (transient)
```

### Constructor Analysis

| Exception | Constructor | Special Properties | Property Accessed? |
|-----------|-------------|-------------------|-------------------|
| AuthenticationExpiredException | `(serviceName, message, previous)` | None | N/A |
| InvalidApiResponseException | `(serviceName, message, previous)` | None | N/A |
| InvalidApiRequestException | `(serviceName, message, previous)` | None | N/A |
| PayloadSerializationException | `(serviceName, message, previous)` | None | N/A |
| ResourceNotFoundException | `(serviceName, resourceType, resourceId, previous)` | `resourceType`, `resourceId` | **NEVER** |
| UnexpectedApiResultException | `(serviceName, reason, previous)` | `reason` | **NEVER** |
| ExternalServiceUnavailableException | `(serviceName, retryAfter, previous)` | `retryAfter` | **50+ usages** |

### Key Finding: Only `retryAfter` Matters

The `resourceType`, `resourceId`, and `reason` properties are **never accessed** outside their constructors. They're only used to build the exception message. They can be absorbed into the message string with zero behavioral impact.

In contrast, `retryAfter` is accessed in **every job** that handles transient failures:
```php
if ($e->retryAfter !== null) {
    $this->release($e->retryAfter);
}
```

## Proposed Design

### New Hierarchy
```
AbstractApiException (abstract)
├── serviceName: string
│
├── PermanentApiFailure (abstract)
│   ├── AuthenticationExpiredException
│   ├── InvalidApiResponseException
│   ├── InvalidApiRequestException
│   ├── PayloadSerializationException
│   ├── ResourceNotFoundException
│   └── UnexpectedApiResultException
│
└── TransientApiFailure (abstract)
    ├── retryAfter: ?int
    └── ExternalServiceUnavailableException
```

### Base Class Definitions

```php
abstract class AbstractApiException extends DomainException
{
    public function __construct(
        public readonly string $serviceName,
        string $message,
        ?Throwable $previous = null,
    ) {
        parent::__construct($message, 0, $previous);
    }
}

abstract class PermanentApiFailure extends AbstractApiException {}

abstract class TransientApiFailure extends AbstractApiException
{
    public function __construct(
        string $serviceName,
        public readonly ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        // Default message generated from serviceName - children can override
        parent::__construct($serviceName, "External service '{$serviceName}' is unavailable", $previous);
    }
}
```

### Simplified Concrete Classes

**Before:**
```php
final class ResourceNotFoundException extends AbstractApiException
{
    public function __construct(
        public readonly string $serviceName,
        public readonly string $resourceType,  // Never accessed
        public readonly int|string $resourceId,  // Never accessed
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            "{$resourceType} with ID '{$resourceId}' not found in {$serviceName}",
            previous: $previous,
        );
    }
}
```

**After:**
```php
final class ResourceNotFoundException extends PermanentApiFailure
{
    public function __construct(
        string $serviceName,
        string $message,  // e.g., "Order '12345' not found"
        ?Throwable $previous = null,
    ) {
        parent::__construct($serviceName, $message, $previous);
    }

    // Factory for common pattern
    public static function forResource(
        string $serviceName,
        string $resourceType,
        int|string $resourceId,
        ?Throwable $previous = null,
    ): self {
        return new self(
            $serviceName,
            "{$resourceType} with ID '{$resourceId}' not found",
            $previous,
        );
    }
}
```

## Benefits

### 1. Simplified Job Exception Handling

**Before (6-exception union types):**
```php
catch (InvalidApiResponseException|AuthenticationExpiredException $e) {
    $this->fail($e);
} catch (InvalidApiRequestException|ResourceNotFoundException|... $e) {
    $this->fail($e);
} catch (ExternalServiceUnavailableException $e) {
    if ($e->retryAfter !== null) {
        $this->release($e->retryAfter);
    }
    throw $e;
}
```

**After:**
```php
catch (TransientApiFailure $e) {
    if ($e->retryAfter !== null) {
        $this->release($e->retryAfter);
    }
    throw $e;
} catch (PermanentApiFailure $e) {
    $this->fail($e);
}
```

### 2. Cleaner @throws Annotations

**Before:**
```php
/**
 * @throws ResourceNotFoundException
 * @throws InvalidApiRequestException
 * @throws InvalidApiResponseException
 * @throws AuthenticationExpiredException
 * @throws ExternalServiceUnavailableException
 */
```

**After:**
```php
/**
 * @throws PermanentApiFailure
 * @throws TransientApiFailure
 */
```

### 3. Future-Proof

Adding new transient failures (e.g., `CircuitBreakerOpenException`) automatically inherits `retryAfter` and correct catch behavior.

### 4. Backward Compatible

Existing `catch (AuthenticationExpiredException $e)` blocks still work — useful for Commands that want specific user messages.

## Migration Path

### Phase 1: Add Base Classes (Backward-Compatible)
1. Create `PermanentApiFailure` abstract class
2. Create `TransientApiFailure` abstract class with `retryAfter` (default message from serviceName)
3. Change `extends AbstractApiException` → `extends PermanentApiFailure/TransientApiFailure`
4. Update internal `parent::__construct()` calls in all 8 exception classes
5. All **external** throw sites unchanged — public constructor signatures preserved

### Phase 2: Simplify Constructors (Breaking)
1. Remove unused properties (`resourceType`, `resourceId`, `reason`)
2. Add factory methods for common patterns (e.g., `ResourceNotFoundException::forResource()`)
3. Update throw sites to use new constructors/factories

### Phase 3: Simplify Catch Blocks (Optional, Gradual)
1. Replace union types with base types in Jobs
2. Keep specific types where needed (Commands)

## Affected Files

### Exception Definitions (8 files)
- `app/Domain/Exceptions/Api/AbstractApiException.php`
- `app/Domain/Exceptions/Api/AuthenticationExpiredException.php`
- `app/Domain/Exceptions/Api/ExternalServiceUnavailableException.php`
- `app/Domain/Exceptions/Api/InvalidApiRequestException.php`
- `app/Domain/Exceptions/Api/InvalidApiResponseException.php`
- `app/Domain/Exceptions/Api/PayloadSerializationException.php`
- `app/Domain/Exceptions/Api/ResourceNotFoundException.php`
- `app/Domain/Exceptions/Api/UnexpectedApiResultException.php`

### Jobs Using `retryAfter` (14+ files)
All jobs in `app/Presentation/Jobs/` that catch `ExternalServiceUnavailableException`

### UseCases with Union Types (2 files)
- `app/Application/Inventory/UseCases/UpdateSkuUseCase.php`
- `app/Application/Product/UseCases/SetProductFreeDeliveryUseCase.php`

## Risk Assessment

| Risk | Likelihood | Mitigation |
|------|------------|------------|
| PHPStan complaints during migration | Medium | Update @throws incrementally |
| Missed throw site after constructor change | Low | PHPStan will catch mismatches |
| Tests asserting specific exception properties | Low | Update tests in Phase 2 |

## Recommendation

**Proceed with Phase 1** first (add base classes, change extends). This is fully backward compatible and immediately enables:
- Simplified catch blocks where desired
- Cleaner @throws documentation
- Type-safe retry semantics

**Phase 2** can be done gradually as part of normal maintenance.

## User Decisions

1. **Factory methods**: ✅ Keep factory methods like `ResourceNotFoundException::forResource()` for common patterns while allowing direct construction for custom messages.

2. **Naming**: ✅ Use `PermanentApiFailure` / `TransientApiFailure` — describes the failure characteristic (permanent = don't retry, transient = retry).

## Implementation Checklist

### Phase 1: Add Base Classes (Backward-Compatible)
- [ ] Add constructor to `AbstractApiException` with `serviceName` property
- [ ] Create `PermanentApiFailure` abstract class extending `AbstractApiException`
- [ ] Create `TransientApiFailure` abstract class extending `AbstractApiException` with `retryAfter` (no message param - generates default)
- [ ] Update 6 permanent exceptions to extend `PermanentApiFailure`, fix `parent::__construct()` calls
- [ ] Update `ExternalServiceUnavailableException` to extend `TransientApiFailure` (public signature unchanged)
- [ ] Run `make lint` and `make test`

### Phase 2: Simplify Constructors
- [ ] Remove `resourceType`, `resourceId` from `ResourceNotFoundException` constructor
- [ ] Add `ResourceNotFoundException::forResource()` factory method
- [ ] Remove `reason` from `UnexpectedApiResultException` constructor
- [ ] Add `UnexpectedApiResultException::withReason()` factory method
- [ ] Update throw sites to use factories or direct messages
- [ ] Run `make lint` and `make test`

### Phase 3: Simplify Catch Blocks (Optional)
- [ ] Update Jobs to catch `TransientApiFailure`/`PermanentApiFailure` instead of unions
- [ ] Update UseCases @throws annotations
- [ ] Keep specific types in Commands where user-friendly messages needed

## Verification

1. `make lint` — PHPStan validates @throws propagation
2. `make test` — All exception tests pass
3. Manual: Verify a job correctly releases on `TransientApiFailure` and fails on `PermanentApiFailure`
