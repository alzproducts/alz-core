# Common Linting Errors & Solutions

This guide documents frequently encountered linting errors and their solutions, ranked from most recommended to least.

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

### References

- [ShipMonk PHPStan Rules Documentation](https://github.com/shipmonk-rnd/phpstan-rules)
- [PHPStan Exception Rules](https://github.com/pepakriz/phpstan-exception-rules)
- Related codebase files:
  - `app/Application/Support/GracefulCache.php` - Best practice example
  - `phpstan.neon` - Global configuration
  - `bootstrap/app.php` - Inline ignore examples