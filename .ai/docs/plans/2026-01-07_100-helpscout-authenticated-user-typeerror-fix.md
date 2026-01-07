# Fix HelpScout AuthenticatedUser TypeError

## Problem Summary

**Bug**: `CachingHelpScoutService::getAgentProfile(): Argument #1 ($email) must be of type string, null given`

**Root Cause**: JWT middleware was refactored from `$request->merge(['auth_user_email' => ...])` to `$request->attributes->set('authenticated_user', $authenticatedUser)`, but several files weren't updated.

**Why PHPStan Didn't Catch It**: The `@var string` annotation tells PHPStan to trust the developer's assertion, bypassing type checking on `$request->input()` which returns `mixed`.

---

## Affected Files

| File | Lines | Issue |
|------|-------|-------|
| `app/Presentation/Http/Controllers/HelpScoutController.php` | 174, 199 | **Production crash** |
| `app/Infrastructure/Sentry/SentryUserContextMiddleware.php` | 28-29 | Silent failure |
| `routes/api.php` | 31-34 | Test endpoint broken |
| `tests/Unit/Presentation/Http/Controllers/HelpScoutControllerTest.php` | 403 | False positive tests |

---

## Approach: Hybrid (Middleware → Attributes, Controllers → DI)

**Architecture:**
```
┌─────────────────────────────────────────────────────────────┐
│  MIDDLEWARE LAYER (request plumbing)                        │
│  → Direct attribute access: $request->attributes->get()     │
│  → Uses instanceof check (handles missing gracefully)       │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│  CONTAINER BINDING (bridge)                                  │
│  → AuthServiceProvider binds AuthenticatedUser              │
│  → Reads from request attributes, provides to DI            │
└─────────────────────────────────────────────────────────────┘
                              ↓
┌─────────────────────────────────────────────────────────────┐
│  CONTROLLER LAYER (business logic)                          │
│  → Method injection: function profile(AuthenticatedUser $u) │
│  → Full type safety, PHPStan enforced                       │
└─────────────────────────────────────────────────────────────┘
```

**Why Method Injection (not Constructor)?**
- `AuthenticatedUser` is **request-scoped** (different user each request)
- With **Octane**, constructor-injected deps persist across requests
- Constructor injection would cause User A's identity to leak to User B!
- Rule: Services → constructor, request-scoped data → method injection

---

## Implementation Plan

### Step 0: Review and Simplify Tests

**Before making changes**, review all test files for affected code against `tests/TestingStrategy.md`:

**Files to review:**
- `tests/Unit/Presentation/Http/Controllers/HelpScoutControllerTest.php`
- Any other tests touching HelpScoutController or SentryUserContextMiddleware

**Apply TestingStrategy.md principles:**
- Presentation layer: minimal testing (smoke/feature tests only)
- Don't test pure delegation
- Remove tests that verify what PHPStan already guarantees
- Focus on critical paths, not implementation details

**Goal**: Simplify/reduce tests before refactoring, not after. Fewer tests = less to update.

---

### Step 1: Create AuthServiceProvider

**File**: `app/Providers/AuthServiceProvider.php` (NEW)

```php
<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\Support\DeferrableProvider;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use LogicException;
use Override;

/**
 * Provides AuthenticatedUser resolution from request attributes.
 *
 * Bridges the middleware layer (which sets authenticated_user in request
 * attributes) with the controller layer (which uses DI for type-safe access).
 *
 * IMPORTANT: Only use in controllers behind auth.supabase middleware.
 * The binding throws if no authenticated user is available.
 */
final class AuthServiceProvider extends ServiceProvider implements DeferrableProvider
{
    #[Override]
    public function register(): void
    {
        // Bind AuthenticatedUser to resolve from current request's attributes
        // NOT a singleton - must resolve fresh for each request (Octane-safe)
        $this->app->bind(
            AuthenticatedUser::class,
            static function (Application $app): AuthenticatedUser {
                $request = $app->make(Request::class);
                \assert($request instanceof Request);

                $user = $request->attributes->get('authenticated_user');

                if (!$user instanceof AuthenticatedUser) {
                    // LogicException: programming error (route missing middleware)
                    // In PHPStan's unchecked list, avoids checkedExceptionInCallable
                    throw new LogicException(
                        'AuthenticatedUser not available. Ensure route has auth.supabase middleware.'
                    );
                }

                return $user;
            },
        );
    }

    /**
     * @return list<class-string>
     */
    #[Override]
    public function provides(): array
    {
        return [AuthenticatedUser::class];
    }
}
```

**Register in `bootstrap/providers.php`**:
```php
return [
    // ...existing providers...
    App\Providers\AuthServiceProvider::class,
];
```

---

### Step 2: Refactor HelpScoutController to Use DI

**File**: `app/Presentation/Http/Controllers/HelpScoutController.php`

**Changes:**
- Remove `Request $request` from methods that only need user
- Add `AuthenticatedUser $user` as method parameter
- Remove private `resolveAgentId(Request)` helper, replace with `resolveAgentId(AuthenticatedUser)`

```php
// BEFORE
public function assigned(Request $request): JsonResponse
{
    $params = ConversationQueryParams::assigned($this->resolveAgentId($request));
    // ...
}

private function resolveAgentId(Request $request): int
{
    /** @var string $email */
    $email = $request->input('auth_user_email');
    return $this->service->getAgentProfile($email)->id;
}

// AFTER
public function assigned(AuthenticatedUser $user): JsonResponse
{
    $params = ConversationQueryParams::assigned($this->resolveAgentId($user));
    // ...
}

private function resolveAgentId(AuthenticatedUser $user): int
{
    return $this->service->getAgentProfile($user->email)->id;
}
```

**Full method updates:**
- `assigned(AuthenticatedUser $user)` - remove Request
- `refreshAssigned(AuthenticatedUser $user)` - remove Request
- `todos(AuthenticatedUser $user)` - remove Request
- `refreshTodos(AuthenticatedUser $user)` - remove Request
- `profile(AuthenticatedUser $user)` - remove Request
- `negativeReviews()` - no change (doesn't need user)
- `refreshNegativeReviews()` - no change
- `escalations(GetEscalationsUseCase $useCase)` - no change
- `refreshEscalations(GetEscalationsUseCase $useCase)` - no change

---

### Step 3: Fix SentryUserContextMiddleware

**File**: `app/Infrastructure/Sentry/SentryUserContextMiddleware.php`

**Add import**: `use App\Domain\Access\ValueObjects\AuthenticatedUser;`

Keep using direct attribute access (middleware layer, not DI):

```php
// BEFORE (broken - uses input())
$userId = $request->input('auth_user_id');
$userEmail = $request->input('auth_user_email');

if (\is_string($userId) && $userId !== '') {
    // ...
}

// AFTER (fixed - uses attributes)
$authenticatedUser = $request->attributes->get('authenticated_user');

if ($authenticatedUser instanceof AuthenticatedUser) {
    configureScope(static function (Scope $scope) use ($authenticatedUser): void {
        $scope->setUser([
            'id' => $authenticatedUser->id,
            'email' => $authenticatedUser->email,
        ]);
    });
}
```

**Note**: Uses `instanceof` (not DI) because:
1. This is middleware layer (request plumbing)
2. May run on routes without authentication
3. Should gracefully no-op if no user

---

### Step 4: Fix Test Route

**File**: `routes/api.php` (lines 31-34)

**Add import at top of file**: `use App\Domain\Access\ValueObjects\AuthenticatedUser;`

Use DI for the test route too:

```php
// BEFORE (broken)
Route::get('user', static fn(Request $request): array => [
    'user_id' => $request->input('auth_user_id'),
    'email' => $request->input('auth_user_email'),
]);

// AFTER (fixed - uses DI)
Route::get('user', static fn(AuthenticatedUser $user): array => [
    'user_id' => $user->id,
    'email' => $user->email,
]);
```

---

### Step 5: Update HelpScoutControllerTest

**File**: `tests/Unit/Presentation/Http/Controllers/HelpScoutControllerTest.php`

**Changes:**
- Update helper to create `AuthenticatedUser` directly (no Request needed)
- Update test methods to pass `AuthenticatedUser` instead of `Request`

```php
// BEFORE
private function createRequestWithEmail(string $email): Request
{
    $request = new Request();
    $request->merge(['auth_user_email' => $email]);
    return $request;
}

// Test usage:
$request = $this->createRequestWithEmail('agent@example.com');
$this->controller->assigned($request);

// AFTER
private function createAuthenticatedUser(
    string $email,
    string $id = '00000000-0000-0000-0000-000000000001',
): AuthenticatedUser {
    return new AuthenticatedUser(
        id: $id,
        email: $email,
        isApproved: true,
    );
}

// Test usage:
$user = $this->createAuthenticatedUser('agent@example.com');
$this->controller->assigned($user);
```

**Note**: Review ALL tests in file during implementation - verify no tests pass `Request` directly or rely on it for headers/other data.

---

## Files to Modify

| File | Action |
|------|--------|
| `tests/Unit/Presentation/Http/Controllers/HelpScoutControllerTest.php` | **REVIEW FIRST** - Simplify per TestingStrategy.md |
| `app/Providers/AuthServiceProvider.php` | **CREATE** - Container binding |
| `bootstrap/providers.php` | **EDIT** - Register provider |
| `app/Presentation/Http/Controllers/HelpScoutController.php` | **EDIT** - Use DI |
| `app/Infrastructure/Sentry/SentryUserContextMiddleware.php` | **EDIT** - Use attributes |
| `routes/api.php` | **EDIT** - Fix test route |

---

## Verification

1. `make lint` - PHPStan validates type safety end-to-end
2. `make test` - All tests pass with new signatures
3. Manual test: HelpScout endpoints with valid JWT

---

## Architecture Decision Record

**Decision**: Use hybrid approach for AuthenticatedUser access

**Context**: JWT middleware refactoring broke controller access to user email

**Options Considered**:
1. Fix with direct attribute access (minimal change)
2. Add container binding + DI (type-safe)
3. Hybrid: middleware uses attributes, controllers use DI

**Choice**: Option 3 (Hybrid)

**Rationale**:
- Middleware is request plumbing → direct attribute access is appropriate
- Controllers are business logic → should get typed dependencies via DI
- Container binding bridges these two layers cleanly
- Method injection (not constructor) because AuthenticatedUser is request-scoped (Octane-safe)

---

## Critical Review Notes

Issues identified and addressed during plan review:

| Issue | Resolution |
|-------|------------|
| `RuntimeException` in `bind()` triggers ShipMonk `checkedExceptionInCallable` | Changed to `LogicException` (in unchecked list) |
| Missing import in `routes/api.php` | Added note to add `use AuthenticatedUser` |
| Missing import in `SentryUserContextMiddleware` | Added note to add import |
| `@var` annotation pattern (same as original bug) | Added defensive `assert()` |
| Test coverage assumption | Added note to verify all tests during implementation |
