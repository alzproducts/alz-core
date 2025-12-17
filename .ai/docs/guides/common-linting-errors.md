# Common Linting Errors & Solutions

This guide documents frequently encountered linting errors and their solutions, ranked from most recommended to least.

---

## General Troubleshooting

### Ignoring Errors on PHPDoc Annotation Lines

When PHPStan reports errors on `@throws`, `@param`, `@return`, or other PHPDoc tags (not on actual code), use `@phpstan-ignore-next-line` **within the docblock**:

```php
/**
 * @phpstan-ignore-next-line some.rule.identifier (reason why it's safe)
 * @throws SomeException Description
 */
```

**Key behavior (PHPStan v2+):**
- `@phpstan-ignore-next-line` within a docblock → ignores errors on the **next annotation line**
- `@phpstan-ignore-next-line` at the **end** of a docblock → ignores errors on the **code line** (method signature)

This is intentional PHPStan v2 behavior. See [PHPStan Issue #12153](https://github.com/phpstan/phpstan/issues/12153) for details.

**References:**
- [PHPStan Ignoring Errors](https://phpstan.org/user-guide/ignoring-errors)

---

## shipmonk.checkedExceptionInCallable

### What It Means

PHPStan's ShipMonk rule `forbidCheckedExceptionInCallable` prevents throwing checked exceptions inside closures, arrow functions, or first-class callables. The rule exists because PHPStan cannot track when a closure will be invoked—if it's stored and called later (or never), exception handling becomes unpredictable.

**Typical error message:**
```
Throwing checked exception ExternalServiceUnavailableException in arrow function!
```

### When It Triggers

- Arrow functions: `fn() => $this->methodThatThrows()`
- Closures: `function() { $this->methodThatThrows(); }`
- First-class callables: `$this->methodThatThrows(...)`
- Anywhere the callable is passed to a method that might defer execution

### Solutions (Best → Worst)

---

#### 1. `@param-immediately-invoked-callable` Annotation (Best)

**When to use:** You own the method accepting the closure, and it's called immediately.

This tells PHPStan the closure executes synchronously within the method, so exceptions propagate normally.

**Example from codebase:** `app/Application/Support/GracefulCache.php:37`

```php
/**
 * @template T
 * @param-immediately-invoked-callable $callback
 *
 * @param Closure(): T $callback
 *
 * @return T
 */
public function remember(string $key, int $ttl, Closure $callback): mixed
{
    // ... cache check ...
    $value = $callback();  // Called immediately - exceptions propagate
    // ... cache store ...
    return $value;
}
```

**Why it's best:** Zero runtime cost, documents intent, exceptions are properly tracked.

---

#### 2. `immediatelyCalledCallables` in phpstan.neon

**When to use:** Third-party method that IS immediately invoked (you don't own the code, can't add annotation).

Configure globally so all usages benefit.

```yaml
parameters:
  shipmonkRules:
    forbidCheckedExceptionInCallable:
      immediatelyCalledCallables:
        'Some\ThirdParty\Class::method': 0  # 0 = first parameter position
```

**Note:** Only use when you're certain the closure is ALWAYS invoked synchronously.

---

#### 3. `allowedCheckedExceptionCallables` in phpstan.neon

**When to use:** Third-party method that safely handles/propagates exceptions, even if not immediately invoked.

Examples: Laravel's Concurrency driver (serializes to child process but propagates exceptions via IPC).

```yaml
parameters:
  shipmonkRules:
    forbidCheckedExceptionInCallable:
      allowedCheckedExceptionCallables:
        'Illuminate\Contracts\Concurrency\Driver::run': 0
        'Symfony\Component\Console\Question::setValidator': 0
```

**Why it works:** Tells ShipMonk "this method handles exceptions correctly, don't check."

---

#### 4. Inline `@phpstan-ignore` with Justification

**When to use:** One-off case where global config isn't appropriate.

Always include a parenthetical explanation of WHY it's safe.

**Example from codebase:** `bootstrap/app.php:25`

```php
// @phpstan-ignore-next-line shipmonk.checkedExceptionInCallable (Laravel rate limiter closures are framework-managed; $request->ip() never returns null)
RateLimiter::for('api', static function (Request $request): Limit {
    // ...
});
```

**Example from codebase:** `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php:181`

```php
/**
 * @phpstan-ignore shipmonk.checkedExceptionInCallable (Pool builds request definitions, doesn't execute HTTP - no exceptions thrown in closure)
 */
$poolResults = Http::pool(fn(Pool $pool): array => $this->buildPoolRequests($pool, $requests));
```

**Why include justification:** Future maintainers (and Claude) understand why the ignore is safe.

---

#### 5. Path-based Ignores in phpstan.neon

**When to use:** Entire directory follows the same pattern (e.g., service providers, route files).

**Example from codebase:** `phpstan.neon:79-88`

```yaml
ignoreErrors:
  # Laravel service providers use closures/callbacks for deferred binding resolution.
  # These ARE invoked synchronously when the service is resolved, but ShipMonk can't verify this.
  -
    identifier: shipmonk.checkedExceptionInCallable
    path: app/Providers/*
  # Laravel route files use closures for route grouping and definitions.
  # All exceptions are handled by Laravel's exception handler, not the closure caller.
  -
    identifier: shipmonk.checkedExceptionInCallable
    path: routes/*
```

**Why it's acceptable:** Reduces noise for known-safe patterns, documents reasoning in config.

---

#### 6. NEVER: Disable Rule Entirely

```yaml
# DON'T DO THIS
parameters:
  shipmonkRules:
    forbidCheckedExceptionInCallable:
      enabled: false
```

Disabling removes protection across the entire codebase. The rule catches real bugs.

---

### Decision Tree

```
Exception thrown in closure/arrow function
    ↓
Do you OWN the method accepting the closure?
    → YES: Add @param-immediately-invoked-callable to parameter → DONE
    → NO: ↓

Is the closure ALWAYS immediately invoked by the third-party method?
    → YES: Add to immediatelyCalledCallables in phpstan.neon → DONE
    → NO: ↓

Does the third-party method safely propagate exceptions?
    → YES: Add to allowedCheckedExceptionCallables in phpstan.neon → DONE
    → NO: ↓

Is this a one-off case?
    → YES: Use @phpstan-ignore with clear justification → DONE
    → NO: ↓

Does entire directory follow same pattern?
    → YES: Add path-based ignore in phpstan.neon → DONE
    → NO: Refactor code to avoid checked exceptions in closure
```

---

## missingType.checkedException (with @param-immediately-invoked-callable)

### What It Means

PHPStan reports that a method throws checked exceptions that aren't documented in its `@throws` tag. However, when using `@param-immediately-invoked-callable` on a closure parameter, PHPStan correctly attributes the closure's exceptions to the calling method—but it **cannot trace through the callee's catch blocks** to see that those exceptions are already handled.

This creates a false positive: PHPStan thinks exceptions escape uncaught when they're actually caught and translated inside the method that invokes the closure.

**Typical error message:**
```
Method App\Infrastructure\Linnworks\LinnworksHttpTransport::get() throws checked
exception Exception but it's missing from the PHPDoc @throws tag.
```

### When It Triggers

- You use `@param-immediately-invoked-callable` to satisfy ShipMonk's `forbidCheckedExceptionInCallable`
- The method accepting the closure has comprehensive exception handling
- PHPStan attributes the closure's exceptions to the caller but doesn't see they're caught

**Without** `@param-immediately-invoked-callable`: You get `shipmonk.checkedExceptionInCallable`
**With** `@param-immediately-invoked-callable`: You get `missingType.checkedException`

This is a **PHPStan limitation**, not a code design problem.

### Solution: Inline `@phpstan-ignore` with Multiple Identifiers

Use a comment on the line **before** the closure definition, listing the identifier once per exception type:

```php
return $this->executeWithAuthRetry(
    // @phpstan-ignore missingType.checkedException, missingType.checkedException, missingType.checkedException
    fn(LinnworksSession $session): Response => $this->createBaseRequest($session)
        ->send('GET', $endpoint, ['query' => $query])
        ->throw(),
    $endpoint,
);
```

**Critical detail**: If there are **N exceptions** on the same line (e.g., `RuntimeException`, `RequestException`, `Exception`), you must list `missingType.checkedException` **N times** in the comma-separated list.

### Why Not Add @throws?

Adding `@throws Exception` or `@throws RequestException` to the public method would:
1. **Lie to callers** - these exceptions ARE caught internally
2. **Defeat the purpose** - the whole point of exception translation is clean domain exceptions at boundaries
3. **Propagate the problem** - callers would then need to handle exceptions that can never escape

### Line Positioning Rules

From [PHPStan documentation](https://phpstan.org/user-guide/ignoring-errors):
- **Standalone comment** (only whitespace on line) → targets the **next** line
- **Inline comment** (same line as code) → targets the **same** line

For multi-line arrow functions, place the ignore comment on its own line immediately before the `fn()` line.

### Codebase Examples

- `app/Infrastructure/Linnworks/LinnworksHttpTransport.php:65` - GET method
- `app/Infrastructure/Linnworks/LinnworksHttpTransport.php:107` - POST method

---

## shipmonk.nonNormalizedType

### What It Means

ShipMonk flags `@throws` declarations listing both specific exceptions and their parent types as "non-normalized". The rule considers listing `ConnectionException` alongside `Exception` redundant since catching `Exception` catches all subtypes.

**Typical error message:**
```
Found non-normalized type (ConnectionException | Exception) for throws: ConnectionException is a subtype of Exception.
```

### When It Triggers

- Documenting `@throws SpecificException` and `@throws Exception` in the same docblock
- The specific exception extends the generic one (directly or indirectly)

### Solution

When you intentionally document all specific exception types for caller clarity (even though a parent type is also listed), use `@phpstan-ignore-next-line` within the docblock before each specific `@throws` line:

```php
/**
 * @phpstan-ignore-next-line shipmonk.nonNormalizedType (specific exceptions documented for caller clarity)
 * @throws ConnectionException When connection fails
 * @phpstan-ignore-next-line shipmonk.nonNormalizedType
 * @throws RequestException When HTTP response indicates error
 * @throws Exception When unexpected error occurs
 */
private function downloadData(): string
```

**Key points:**
- Each `@phpstan-ignore-next-line` suppresses the error on the following `@throws` line
- Only needed for specific exceptions that have the parent type also listed
- `@throws Exception` at the end doesn't need an ignore (it's the "normalized" parent)

See [General Troubleshooting](#ignoring-errors-on-phpdoc-annotation-lines) for the underlying PHPStan v2 behavior.

**Related codebase files:**
- `app/Infrastructure/BingAds/BingAdsTransport.php` - Pattern example

---

### References

- [ShipMonk PHPStan Rules Documentation](https://github.com/shipmonk-rnd/phpstan-rules)
- [PHPStan Exception Rules](https://github.com/pepakriz/phpstan-exception-rules)
- Related codebase files:
  - `app/Application/Support/GracefulCache.php` - Best practice example
  - `phpstan.neon` - Global configuration
  - `bootstrap/app.php` - Inline ignore examples