# Test User Persona Resolver

## Problem

Local development uses Supabase test users (e.g., `tom@alzadmin.test`) but external services like HelpScout need real developer emails. Current `handleLocalBypass()` also uses invalid UUID default `'local-test-user'` which fails `Assert::uuid()`.

## Solution

**Allow-list pattern**: Test emails hardcoded in config (security), real email from `EMAIL_TOM_MAIN` env var (not in VCS), full persona (role/departments) fixed per test email.

---

## Files to Create

### 1. `config/local-development.php`

```php
<?php

declare(strict_types=1);

return [
    'test_user_personas' => [
        'tom@alzadmin.test' => [
            'email' => env('EMAIL_TOM_MAIN'),  // Resolved at config load time
            'user_id' => '00000000-0000-0000-0000-000000000001',
            'is_approved' => true,
            'role_name' => 'admin',
            'departments' => null,
        ],
        'default-guest@alzadmin.test' => [
            'email' => env('EMAIL_TOM_MAIN'),  // Same developer, different persona
            'user_id' => '00000000-0000-0000-0000-000000000002',
            'is_approved' => true,
            'role_name' => 'guest',
            'departments' => null,
        ],
    ],
];
```

**Note**: Email resolved via `env()` at config load time (Laravel convention), not at runtime.

### 2. `app/Infrastructure/LocalDevelopment/TestUserPersonaResolver.php`

Responsibilities:
- `hasPersona(string $testEmail): bool` - check if test email is in allow-list
- `resolve(string $testEmail): AuthenticatedUser` - return full persona
- `fromConfig(): self` - static factory from Laravel config

Key behaviors:
- Normalize emails to lowercase
- Throw `RuntimeException` if test email not in allow-list
- **Throw `RuntimeException` if `$persona['email']` is empty/null** (with message: "EMAIL_TOM_MAIN env var not configured")
- Use valid UUIDs that pass `Assert::uuid()`
- Read `$persona['email']` directly (already resolved at config load time)

---

## Files to Modify

### 3. `app/Presentation/Http/Middleware/ValidateSupabaseJwtMiddleware.php`

Simplify `handleLocalBypass()` to use personas exclusively (no legacy fallback):
1. Get test email from `services.supabase.local_test_email` config
2. Create resolver via `TestUserPersonaResolver::fromConfig()`
3. Call `resolver->resolve($testEmail)` - throws `RuntimeException` if not configured
4. Log `api.auth.local_bypass` with resolved email details

**Removed**: Legacy fallback code and `parseDepartmentsConfig()` method (no longer needed).

### 4. `config/services.php` (line 48)

Change default from `'local-test-user'` to `'00000000-0000-0000-0000-000000000001'` to fix UUID validation failure.

### 5. `.env.example`

Add comment explaining `EMAIL_TOM_MAIN` is used for test persona email resolution.

### 6. `CLAUDE.md`

Add "Local Development" section documenting:
- Test user persona system overview
- How to add new personas
- Reference to `ALZ_ADMIN` env var (path to alz-admin project: `/Users/tom/WebstormProjects/alz-admin`)

---

## Tests to Create

Per `tests/TestingStrategy.md`, Infrastructure layer tests should be minimal:
- No coverage target enforced
- No mutation testing
- 2-3 tests: one happy path, one-two error paths

### 7. `tests/Unit/Infrastructure/LocalDevelopment/TestUserPersonaResolverTest.php`

**3 tests only** (following Infrastructure layer strategy):
- `it resolves test email to authenticated user` — happy path, verify all fields
- `it throws for unknown test email` — error: not in allow-list
- `it throws when persona email not configured` — error: env var missing

**Removed** (over-testing for Infrastructure):
- ~~Email case normalization~~ — internal implementation detail
- ~~Role/approval per persona~~ — covered by happy path
- ~~UUID validation~~ — PHPStan + Assert::uuid() handles this

### 8. `tests/Integration/LocalBypassPersonaTest.php`

**2 tests** (tests the real boundary - middleware using resolver):
- `it uses persona when test email is configured` — happy path through middleware
- `it rejects request when persona not configured` — error path (no silent fallback)

---

## Implementation Order

1. Create `config/local-development.php`
2. Create `TestUserPersonaResolver.php`
3. Fix UUID default in `config/services.php`
4. Modify middleware `handleLocalBypass()`
5. Update `.env.example`
6. Update `CLAUDE.md`
7. Write unit tests for resolver
8. Write integration test
9. Run `make lint` and `make test`

---

## Security Notes

- **Allow-list enforced**: Only hardcoded test emails can be mapped
- **Env var for real email**: Developer's actual email never in version control
- **Fixed personas**: Role/permissions defined in code, not configurable via env
- **Local-only**: Only runs when `APP_ENV=local` + localhost IP + bypass header