# Fix: DatabaseGateway misclassifies connection-time PDOExceptions as permanent failures

## Context

**Sentry Issue**: ALZ-CORE-49 (6 events, regressed)

**Problem**: When Supabase pooler has a connection timeout, `PDO::connect()` throws a `PDOException` with an **integer** driver code (`7`) instead of the SQLSTATE **string** (`'08006'`). `DatabaseGateway::extractSqlState()` checks `is_string($previous->getCode())`, gets `false` for integer `7`, returns `null`, and the transient detection is bypassed. The error is wrapped in `DatabaseOperationFailedException` (permanent) instead of `ExternalServiceUnavailableException` (transient/retryable).

**Impact**: Any job hitting a Supabase connection blip is permanently failed instead of retried. Affects all code paths through `DatabaseGateway::query()` and `transact()` — every repository in the system.

**Root cause confirmed by Sentry stacktrace**: Line 174 (`DatabaseOperationFailedException`) is hit for SQLSTATE `08006`, which IS in the `TRANSIENT_ERROR_CODES` list — proving the SQLSTATE was never extracted.

## Files to Modify

1. `app/Infrastructure/Database/DatabaseGateway.php` — fix `extractSqlState()`
2. `tests/Integration/Infrastructure/Database/DatabaseGatewayTest.php` — add test coverage

## Implementation

### Step 1: Fix `extractSqlState()` in `DatabaseGateway.php` (line 193-201)

Current:
```php
private function extractSqlState(QueryException $e): ?string
{
    $previous = $e->getPrevious();
    if (($previous instanceof PDOException) && \is_string($previous->getCode())) {
        return $previous->getCode();
    }
    return null;
}
```

Updated:
```php
private function extractSqlState(QueryException $e): ?string
{
    $previous = $e->getPrevious();

    if (!$previous instanceof PDOException) {
        return null;
    }

    // Query-time PDOExceptions return SQLSTATE as string (e.g., '23505')
    $code = $previous->getCode();
    if (\is_string($code) && $code !== '' && $code !== '0') {
        return $code;
    }

    // Connection-time PDOExceptions (PDO::connect) return integer driver
    // codes instead of SQLSTATE strings. Parse from the standardised
    // "SQLSTATE[XXXXX]" prefix in the message as a fallback.
    if (\preg_match('/SQLSTATE\[(\w{5})]/', $previous->getMessage(), $matches) === 1) {
        return $matches[1];
    }

    return null;
}
```

**Key decisions**:
- `$code !== '0'`: Some drivers return string `'0'` as default — not a valid SQLSTATE
- Regex `\w{5}`: SQLSTATE codes are always exactly 5 alphanumeric characters
- Parsing the message is safe — PDO's `SQLSTATE[XXXXX]` format is standardised and always present

### Step 2: Add test coverage in `DatabaseGatewayTest.php`

Add one new test method that reproduces the exact Sentry scenario:

```php
#[Test]
public function query_translates_connection_timeout_with_integer_pdo_code_to_transient(): void
{
    $gateway = $this->createGateway();

    // PDO::connect() failures return integer driver code, not SQLSTATE string.
    // The SQLSTATE is only in the message: "SQLSTATE[08006] [7] connection ... timeout"
    $pdoException = new PDOException(
        'SQLSTATE[08006] [7] connection to server at "pooler.supabase.com", port 5432 failed: timeout expired'
    );
    // PDOException::$code is public and string by default, but connection
    // failures from PDO::connect() set it to the integer driver error code.
    $reflection = new \ReflectionProperty(PDOException::class, 'code');
    $reflection->setValue($pdoException, 7);

    $queryException = new QueryException('pgsql', 'SELECT * FROM "sync_cursors" LIMIT 1', [], $pdoException);

    $this->expectException(ExternalServiceUnavailableException::class);

    $gateway->query(static fn() => throw $queryException);
}
```

**Why ReflectionProperty**: `PDOException::$code` is typed as `string` in its declaration but PHP's PDO driver internally sets it to an integer for connection failures. We use reflection to reproduce this real-world behavior accurately.

## Verification

1. `make test` — all existing tests pass (no regressions)
2. New test passes — connection timeout PDOException correctly yields `ExternalServiceUnavailableException`
3. `make lint` — linters pass
4. Existing test `query_translates_permanent_error_to_database_operation_failed` still passes — non-PDO previous exceptions still correctly classified as permanent
