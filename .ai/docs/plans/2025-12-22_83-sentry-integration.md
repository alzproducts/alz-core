# Sentry Integration Plan

> **GitHub Issue:** [#83 - feat: Integrate Sentry error monitoring with Octane support](https://github.com/alzproducts/alz-core/issues/83)

## Overview

Install and configure Sentry error monitoring for Laravel 12 + Octane (Swoole) with Clean Architecture compliance.

## Configuration Decisions

- **Performance Monitoring**: Full tracing (10% sample rate)
- **Environments**: Single Sentry project with environment tags
- **Exception Strategy**: API-first (report ValidationException/404s, they're bugs)

## Critical Review Corrections

- **Octane**: sentry-laravel v4 has built-in Octane support - no custom flush needed
- **Middleware**: JWT is route-level, so Sentry context must also be route-level
- **dontReport**: Use Sentry's `ignore_exceptions` config to preserve security logging
- **DSN Validation**: Only require in production environment
- **Throttling**: Use Sentry's `before_send` (not Laravel's `reportable`) to preserve Laravel logs
- **Config Cache**: Use invokable class (not closure) to support `config:cache`
- **Callable Registration**: Set `before_send` via booting callback (class names aren't callable)

---

## Phase 1: Package Installation

```bash
composer require sentry/sentry-laravel:^4.0
php artisan sentry:publish
```

---

## Phase 2: Environment Variables

**File: `.env.example`**

```env
# Sentry Error Monitoring
SENTRY_LARAVEL_DSN=
SENTRY_TRACES_SAMPLE_RATE=0.1
SENTRY_PROFILES_SAMPLE_RATE=0.1
```

---

## Phase 3: Configure config/sentry.php

Customize published config:

```php
<?php

declare(strict_types=1);

return [
    'dsn' => env('SENTRY_LARAVEL_DSN'),
    'environment' => env('APP_ENV', 'production'),
    'traces_sample_rate' => (float) env('SENTRY_TRACES_SAMPLE_RATE', 0.1),
    'profiles_sample_rate' => (float) env('SENTRY_PROFILES_SAMPLE_RATE', 0.1),
    'release' => env('SENTRY_RELEASE'),
    'send_default_pii' => false,

    'breadcrumbs' => [
        'logs' => true,
        'cache' => true,
        'livewire' => false,
        'sql_queries' => true,
        'sql_bindings' => false,
        'queue_info' => true,
        'command_info' => true,
        'http_client_requests' => true,
    ],

    'controllers_base_namespace' => 'App\\Presentation\\Http\\Controllers',
];
```

---

## Phase 4: Exception Strategy

### 4a. Create Invokable Class for before_send

> **IMPORTANT**: Cannot use closures in config - breaks `php artisan config:cache`.

**New File: `app/Infrastructure/Sentry/SentryBeforeSendCallback.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Infrastructure\Exceptions\ApiRateLimitException;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Sentry\Event;
use Sentry\EventHint;

/**
 * Throttle noisy exceptions to Sentry (1-in-10 sampling).
 *
 * Laravel still logs 100% of exceptions normally.
 * This only affects what gets sent to Sentry.
 */
final class SentryBeforeSendCallback
{
    /** @var array<class-string> */
    private const array THROTTLED_EXCEPTIONS = [
        ExternalServiceUnavailableException::class,
        ApiRateLimitException::class,
        ThrottleRequestsException::class,
    ];

    public function __invoke(Event $event, ?EventHint $hint): ?Event
    {
        $exception = $hint?->exception;

        foreach (self::THROTTLED_EXCEPTIONS as $class) {
            if ($exception instanceof $class) {
                // Sample 10% of transient failures
                return random_int(1, 10) === 1 ? $event : null;
            }
        }

        return $event;
    }
}
```

### 4b. Configure config/sentry.php

```php
// In config/sentry.php

'ignore_exceptions' => [
    // Expected flows, not bugs - don't send to Sentry
    // (Laravel still logs to security channel)
    \Illuminate\Auth\AuthenticationException::class,
    \App\Domain\Exceptions\AuthenticationExpiredException::class,
],

// NOTE: before_send is set via AppServiceProvider, not here
// (class name strings aren't callable by Sentry SDK)
```

### 4c. Register before_send in AppServiceProvider

Sentry SDK needs an actual callable, not a class name string.
Set the instance at boot time (before Sentry reads config):

**File: `app/Providers/AppServiceProvider.php`**

```php
use App\Infrastructure\Sentry\SentryBeforeSendCallback;

public function register(): void
{
    // Set Sentry before_send callback before Sentry initializes
    // (must be in register(), not boot(), to run before Sentry's ServiceProvider)
    $this->app->booting(function (): void {
        if (class_exists(\Sentry\SentrySdk::class)) {
            config(['sentry.before_send' => new SentryBeforeSendCallback()]);
        }
    });
}
```

**Why this works:**
- `booting` callback runs before Sentry reads config
- Instance is created at runtime, not serialized in `config:cache`
- `config:cache` still works (stores null for `before_send`, overwritten at boot)

No changes to `bootstrap/app.php` - existing `ThrottleRequestsException` render() is unchanged.

---

## Phase 5: User Context Middleware

**New File: `app/Infrastructure/Sentry/SentryUserContextMiddleware.php`**

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\Sentry;

use Closure;
use Illuminate\Http\Request;
use Sentry\State\Scope;
use Symfony\Component\HttpFoundation\Response;

use function Sentry\configureScope;

/**
 * Attach authenticated user context to Sentry.
 *
 * Reads auth_user_id/auth_user_email attached by ValidateSupabaseJwtMiddleware.
 * MUST run AFTER ValidateSupabaseJwtMiddleware in the middleware chain.
 */
final class SentryUserContextMiddleware
{
    /**
     * @param Closure(Request): Response $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $userId = $request->input('auth_user_id');
        $userEmail = $request->input('auth_user_email');

        if ($userId !== null) {
            configureScope(static function (Scope $scope) use ($userId, $userEmail): void {
                $scope->setUser([
                    'id' => $userId,
                    'email' => $userEmail,
                ]);
            });
        }

        return $next($request);
    }
}
```

> **IMPORTANT: Route-Level Registration**
>
> JWT middleware is registered at ROUTE level (`routes/api.php:22`), not middleware group.
> Sentry context must ALSO be route-level to run AFTER JWT:

**File: `routes/api.php` (modify line 22)**

```php
// BEFORE:
Route::middleware(['throttle:api', ValidateSupabaseJwtMiddleware::class])->group(...)

// AFTER:
Route::middleware([
    'throttle:api',
    ValidateSupabaseJwtMiddleware::class,
    SentryUserContextMiddleware::class,  // Must be AFTER JWT
])->group(...)
```

**DO NOT** use `appendToGroup('api')` in bootstrap - that runs BEFORE route middleware!

---

## Phase 6: Octane Integration

**AUTOMATIC - No custom code needed!**

sentry-laravel v4.x has [built-in Octane support](https://github.com/getsentry/sentry-laravel/pull/495):
- Automatically flushes scope between requests
- Correctly handles ticks and tasks
- No memory leaks from scope stacking

The SDK detects Octane and registers its own listeners. Do NOT create custom `FlushSentryScope` - it would conflict with built-in handling.

Just ensure `config/sentry.php` is published (Phase 2).

---

## Phase 7: Deptrac Configuration

**File: `deptrac.yaml`**

Add after line 102 (LeagueFlysystem):

```yaml
    # Sentry SDK
    - name: SentrySdk
      collectors:
        - type: classLike
          value: ^Sentry\\.*
```

Update rulesets:

```yaml
    Infrastructure:
      # ... existing ...
      - SentrySdk  # Add at end

    Presentation:
      # ... existing ...
      - SentrySdk  # Add at end

    # Add at end with other external layers
    SentrySdk: ~
```

---

## Phase 8: Production Validation

**File: `app/Providers/AppServiceProvider.php`**

The `validateProductionEnvironment()` method already only runs in production.
Add to `$required` array:

```php
'sentry.dsn' => 'Sentry DSN (SENTRY_LARAVEL_DSN)',
```

**Note:** This is safe because:
- Method only runs when `app()->isProduction()` is true
- Local/staging can have empty DSN (Sentry disabled)
- Production will fail fast if DSN missing

---

## Phase 9: Optional Sentry Log Channel

**File: `config/logging.php`**

```php
'sentry' => [
    'driver' => 'sentry',
    'level' => env('LOG_LEVEL', 'error'),
    'bubble' => true,
],
```

---

## Implementation Order

| #   | Action                                                           | Verify                              |
|-----|------------------------------------------------------------------|-------------------------------------|
| 1   | `composer require sentry/sentry-laravel`                         | `composer show sentry/sentry-laravel` |
| 2   | `php artisan sentry:publish`                                     | File exists: `config/sentry.php`    |
| 3   | Create `app/Infrastructure/Sentry/SentryBeforeSendCallback.php`  | `make lint`                         |
| 4   | Customize `config/sentry.php` (ignore_exceptions + traces)       | Review                              |
| 5   | Update `AppServiceProvider.php` (booting callback for before_send) | `make lint`                       |
| 6   | Update `.env.example`                                            | Review                              |
| 7   | Update `deptrac.yaml`                                            | `make deptrac`                      |
| 8   | Create `app/Infrastructure/Sentry/SentryUserContextMiddleware.php` | `make lint`                       |
| 9   | Update `routes/api.php` (add middleware after JWT)               | `make lint`                         |
| 10  | Update `AppServiceProvider.php` (production DSN validation)      | `make lint`                         |
| 11  | Optional: Update `config/logging.php` (sentry channel)           | Review                              |
| 12  | Full validation                                                  | `make check`                        |

---

## Files Summary

### New Files (2)

- `app/Infrastructure/Sentry/SentryUserContextMiddleware.php` (user context)
- `app/Infrastructure/Sentry/SentryBeforeSendCallback.php` (throttling)

### Modified Files (6)

- `composer.json` (via composer require)
- `config/sentry.php` (publish + customize with ignore_exceptions + before_send)
- `.env.example`
- `deptrac.yaml`
- `routes/api.php` (add SentryUserContextMiddleware after JWT)
- `app/Providers/AppServiceProvider.php`

### Unchanged

- `config/octane.php` - sentry-laravel handles Octane automatically
- `bootstrap/app.php` - no changes needed (throttling is in sentry.php)

### Optional

- `config/logging.php` (sentry channel)
- `tests/Unit/Infrastructure/Sentry/` (unit tests)
- `tests/Feature/Sentry/` (integration tests)

---

## Clean Architecture Compliance

| Layer          | Changes                        | Valid |
|----------------|--------------------------------|-------|
| Domain         | None                           | ✓     |
| Application    | None                           | ✓     |
| Infrastructure | New Sentry middleware/listener | ✓     |
| Presentation   | Exception config in bootstrap  | ✓     |

---

## Testing Strategy

**Unit tests for middleware:**
- Attaches user context when `auth_user_id` present
- Handles missing auth data gracefully

**Integration tests:**
- Verify `dontReport` list filters correctly
- Verify throttled exceptions sample at ~10%
