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
        'guest@example.com' => [
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

Modify `handleLocalBypass()` to:
1. Get test email from existing `services.supabase.local_test_email` config
2. Create resolver via `TestUserPersonaResolver::fromConfig()` (returns empty resolver if config missing)
3. If `hasPersona()` returns true: **wrap `resolve()` in try-catch**
   - Success: use resolved `AuthenticatedUser`, log `api.auth.local_bypass_persona`
   - `RuntimeException`: log warning with error message, fall through to legacy
4. If `hasPersona()` returns false: fall back to legacy config with **warning log**: "external services like HelpScout won't work"

Also fix legacy fallback default UUID from `'local-test-user'` to `'00000000-0000-0000-0000-000000000001'`.

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

### 7. `tests/Unit/Infrastructure/LocalDevelopment/TestUserPersonaResolverTest.php`

- `it resolves known test email to authenticated user`
- `it throws for unknown test email`
- `it throws when email is empty or null`
- `it normalizes email case`
- `it returns correct role and approval status per persona`
- `it produces valid UUIDs`

### 8. `tests/Feature/LocalBypassPersonaTest.php` (integration)

- `it uses persona when test email is configured`
- `it falls back to legacy config when persona not found`

---

## Implementation Order

1. Create `config/local-development.php`
2. Create `TestUserPersonaResolver.php`
3. Write unit tests for resolver
4. Fix UUID default in `config/services.php`
5. Modify middleware `handleLocalBypass()`
6. Write integration test
7. Update `.env.example`
8. Update `CLAUDE.md`
9. Run `make lint` and `make test`

---

## Security Notes

- **Allow-list enforced**: Only hardcoded test emails can be mapped
- **Env var for real email**: Developer's actual email never in version control
- **Fixed personas**: Role/permissions defined in code, not configurable via env
- **Local-only**: Only runs when `APP_ENV=local` + localhost IP + bypass header