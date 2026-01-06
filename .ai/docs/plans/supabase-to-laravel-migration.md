# Migration Plan: Supabase Database Ownership to Laravel

> **GitHub Issue**: [#76](https://github.com/alzproducts/alz-core/issues/76)

> **Note**: This plan follows the Clean Architecture already established in this project (per `CLAUDE.md`). Eloquent models go in Infrastructure layer, repository interfaces in Domain layer.

## Goal
Transfer database schema ownership from Supabase migrations to Laravel while:
- Keeping Supabase Auth (MFA, custom JWT claims, RLS) **unchanged**
- Enabling Eloquent ORM for backend data access
- Quick cutover during alpha stage

## Architecture Decision

**API-First**: Frontend calls Laravel API for data. Supabase provides Auth runtime only.

```
┌─────────────────────────────────────────────────────────────────┐
│                        Supabase                                  │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────────────┐  │
│  │  PostgreSQL  │  │     Auth     │  │      Storage         │  │
│  │  (hosting)   │  │  (MFA/JWT)   │  │     (avatars)        │  │
│  └──────────────┘  └──────────────┘  └──────────────────────┘  │
└─────────────────────────────────────────────────────────────────┘
         ▲
         │
┌─────────────────┐
│    Laravel      │ ◄─── Frontend calls API (JWT in header)
│   (Eloquent)    │
│  RLS-protected  │ ◄─── Sets user context for RLS
└─────────────────┘
```

**Key Change**: Frontend no longer queries DB directly via Supabase client. All data access goes through Laravel API. Laravel preserves RLS by setting PostgreSQL session variables.

---

## 🚨 Critical Security Requirements

Before implementation, these MUST be addressed:

**Existing middleware:** `app/Presentation/Http/Middleware/ValidateSupabaseJwtMiddleware.php`

### 1. MFA Bypass Prevention

Frontend enforces MFA via AAL level check (AAL1 = password only, AAL2 = MFA verified). Laravel API must also enforce this to prevent bypass.

**Current state:** Middleware does NOT check AAL level.

**Required change:**
```php
// In ValidateSupabaseJwtMiddleware::handle()
$aal = $decoded->aal ?? 'aal1';
if ($aal !== 'aal2') {
    return response()->json(['error' => 'MFA verification required'], 403);
}
```

### 2. JWT Custom Claims Extraction

Frontend Edge Function injects custom claims into JWT (`app_metadata`). Laravel must extract and use these for authorization.

**Current state:** Only extracts `sub` and `email`. Missing custom claims.

**Required change:**
```php
// Extract custom claims from app_metadata
$appMetadata = $decoded->app_metadata ?? new \stdClass();
$request->merge([
    'auth_user_id' => $userId,
    'auth_user_email' => $userEmail,
    'auth_is_approved' => $appMetadata->is_approved ?? false,
    'auth_role_name' => $appMetadata->role_name ?? null,
    'auth_departments' => $appMetadata->departments_summary ?? null,
]);
```

**Required**: Update middleware to extract these claims.

### 3. User Approval Enforcement

Create a **separate middleware** for approval checking (Single Responsibility Principle). This provides:
- Clear visibility in route definitions
- Isolated testing
- Explicit security documentation

**File:** `app/Presentation/Http/Middleware/EnsureUserApprovedMiddleware.php`

```php
/**
 * ============================================================
 * CRITICAL SECURITY MIDDLEWARE - DO NOT REMOVE OR BYPASS
 * ============================================================
 *
 * Enforces user approval status. All users must be explicitly
 * approved by an admin before accessing the API.
 *
 * MUST run AFTER ValidateSupabaseJwtMiddleware (requires auth claims).
 * ============================================================
 */
final class EnsureUserApprovedMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        $isApproved = $request->input('auth_is_approved', false);

        if ($isApproved !== true) {
            Log::channel('security')->warning('Unapproved user attempted access', [
                'event' => 'api.auth.unapproved_user',
                'user_id' => $request->input('auth_user_id'),
                'email' => $request->input('auth_user_email'),
                'path' => $request->path(),
            ]);

            return response()->json([
                'error' => 'Account pending approval',
                'code' => 'ACCOUNT_NOT_APPROVED',
            ], 403);
        }

        return $next($request);
    }
}
```

**Register as middleware group** (ensures correct ordering):
```php
// bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    $middleware->appendToGroup('auth.supabase', [
        ValidateSupabaseJwtMiddleware::class,
        EnsureUserApprovedMiddleware::class,
    ]);
})
```

**Usage in routes:**
```php
Route::middleware(['auth.supabase'])->group(function () {
    // All routes require valid JWT + approved user
});
```

### 4. Local Development Bypass Updates

The existing `handleLocalBypass()` in `ValidateSupabaseJwtMiddleware` must be updated to include all new claims. Otherwise, local development will be blocked by `EnsureUserApprovedMiddleware`.

**Required change:**
```php
private function handleLocalBypass(Request $request, Closure $next): Response
{
    $request->merge([
        'auth_user_id' => config('services.supabase.local_test_user_id', 'local-test-user'),
        'auth_user_email' => config('services.supabase.local_test_email'),
        'auth_is_approved' => config('services.supabase.local_test_approved', true),
        'auth_role_name' => config('services.supabase.local_test_role', 'admin'),
        'auth_departments' => config('services.supabase.local_test_departments'),
    ]);

    return $next($request);
}
```

**Add to `.env.example`:**
```env
# Local auth bypass (development only)
SUPABASE_LOCAL_BYPASS_SECRET=your-secret-here
SUPABASE_LOCAL_TEST_EMAIL=dev@example.com
SUPABASE_LOCAL_TEST_USER_ID=local-test-user
SUPABASE_LOCAL_TEST_APPROVED=true
SUPABASE_LOCAL_TEST_ROLE=admin
```

---

## RLS Preservation Pattern

Laravel preserves RLS by setting PostgreSQL session variables before queries.

**IMPORTANT:** The variable format is `request.jwt.claims` as a JSON object (verified from `supabase/tests/critical_rls.sql`):

```php
// app/Infrastructure/Persistence/Concerns/WithUserContext.php

/**
 * Provides RLS-aware database query execution.
 *
 * WARNING: Cache + RLS Conflict
 * ─────────────────────────────
 * Do NOT cache results from runAsUser() with shared cache keys.
 * RLS filtering is user-specific; cached results would bypass RLS.
 *
 * ✅ SAFE:   Cache::remember("profile:{$userId}", fn() => $this->runAsUser(...))
 * ✅ SAFE:   Cache::remember("roles:all", fn() => $this->runAsService(...))
 * ❌ DANGER: Cache::remember("dept:{$deptId}", fn() => $this->runAsUser(...))
 *
 * Rule: If runAsUser() is involved, cache key MUST include user ID.
 */
trait WithUserContext
{
    protected function runAsUser(string $userId, callable $query): mixed
    {
        return DB::transaction(function () use ($userId, $query) {
            // Set user context - RLS policies now apply
            // Format: JSON object with 'sub' key (matches Supabase's auth.uid() implementation)
            DB::statement(
                "SELECT set_config('request.jwt.claims', ?, true)",
                [json_encode(['sub' => $userId], JSON_THROW_ON_ERROR)]
            );
            return $query();
        });
    }

    protected function runAsService(callable $query): mixed
    {
        return DB::transaction(function () use ($query) {
            // Explicitly clear any previous user context (connection pooling safety)
            // Without this, a previous request's user context could leak via pooled connections
            DB::statement("SELECT set_config('request.jwt.claims', '{}', true)");
            return $query();
        });
    }
}
```

**Usage in repositories:**
```php
public function findForUser(string $userId): ?Profile
{
    return $this->runAsUser($userId, fn() => ProfileModel::first());
}
```

**Background Jobs:** Jobs should use `runAsUser($userId, ...)` when operating on behalf of a user, or `runAsService(...)` for system operations.

---

## Phase 1: Database Configuration (~30 min)

### 1.1 Update `config/database.php`
Add multi-schema search path:

```php
'pgsql' => [
    // ...existing...
    'search_path' => 'public,access,config,utils',
],
```

### 1.2 Verify Connection
Test that Laravel can query across schemas before proceeding.

---

## Phase 2: Adoption Migrations (~2-3 hours)

Create **non-destructive** migrations that document existing schema. Pattern:

```php
public function up(): void
{
    if (Schema::hasTable('profiles')) {
        return; // Already exists - adoption complete
    }
    // Create for testing/CI only
    Schema::create('profiles', ...);
}
```

### Migration Order (15 files)

| Order | Migration | Tables/Objects |
|-------|-----------|----------------|
| 1 | `adopt_utils_schema` | utils schema + helper functions |
| 2 | `adopt_access_schema` | access schema (empty) |
| 3 | `adopt_config_schema` | config schema (empty) |
| 4 | `adopt_profiles_table` | public.profiles |
| 5 | `adopt_auth_allowed_domains_table` | public.auth_allowed_domains |
| 6 | `adopt_auth_allowed_emails_table` | public.auth_allowed_emails |
| 7 | `adopt_user_api_keys_table` | public.user_api_keys |
| 8 | `adopt_system_cache_table` | public.system_cache |
| 9 | `adopt_access_roles_table` | access.roles |
| 10 | `adopt_access_permissions_table` | access.permissions |
| 11 | `adopt_access_departments_table` | access.departments |
| 12 | `adopt_access_user_roles_table` | access.user_roles |
| 13 | `adopt_access_user_departments_table` | access.user_departments |
| 14 | `adopt_access_role_permissions_table` | access.role_permissions |
| 15 | `adopt_config_dashboard_table` | config.dashboard |

### RLS Policy Handling
- **Keep in Supabase migrations** - RLS policies remain as-is
- **Laravel respects RLS** via `WithUserContext` trait (sets session variables)
- **Document in Laravel migration comments** for reference
- No need to replicate RLS logic in application code

---

## Phase 3: Eloquent Models (~2-3 hours)

### Directory Structure

```
app/Infrastructure/Persistence/
├── Models/
│   ├── ProfileModel.php
│   ├── AuthAllowedDomainModel.php
│   ├── AuthAllowedEmailModel.php
│   ├── UserApiKeyModel.php
│   ├── SystemCacheModel.php
│   └── Access/
│       ├── RoleModel.php
│       ├── PermissionModel.php
│       ├── DepartmentModel.php
│       └── UserPermissionModel.php  (only pivot with meaningful extra columns)
├── Config/
│   └── DashboardConfigModel.php
├── Repositories/
│   └── EloquentProfileRepository.php
└── Concerns/
    └── HasAuditFields.php
```

### Eloquent Model Conventions

These conventions ensure consistency and avoid over-engineering:

#### 1. Mass Assignment (`$fillable` / `$guarded`)

**Don't define `$fillable` or `$guarded` by default.** Add only when needed.

- Mass assignment protection only applies to `create([...])`, `fill([...])`, `update([...])`
- Direct property assignment (`$model->name = 'foo'`) ignores these settings
- Reading from DB (`find()`, `where()->get()`) ignores these settings
- Laravel throws clear `MassAssignmentException` if you forget - add then

#### 2. Timestamps

**Always set `$timestamps = false`** - PostgreSQL triggers manage `created_at`/`updated_at`.

#### 3. Primary Key Configuration

| PK Type | Properties to set |
|---------|-------------------|
| UUID | `use HasUuids`, `$incrementing = false`, `$keyType = 'string'` |
| Auto-increment int | `$incrementing = true` (default), `$keyType = 'int'` (default) |
| Custom string | `$primaryKey = 'key'`, `$incrementing = false`, `$keyType = 'string'` |

#### 4. Schema Prefixes

Non-public schema tables need explicit table names:
```php
protected $table = 'access.roles';
protected $table = 'config.dashboard';
```

#### 5. Casts

Use `casts()` method for type conversion:
```php
protected function casts(): array
{
    return [
        'is_approved' => 'boolean',
        'settings' => 'array',  // JSON columns
        'created_at' => 'immutable_datetime',  // Prefer immutable for safety
    ];
}
```

#### 6. Relationships

**Define all relationships upfront** - they're self-documenting, zero runtime cost if unused, and immediately available when needed.

#### 7. PHPDoc `@property` Annotations

**Include `@property` annotations** for all columns:
- Enables IDE autocompletion
- Catches typos at development time
- PHPStan knows the types
- Can be regenerated with `php artisan ide-helper:models`

#### 8. Pivot Table Models

**Only create models for pivots with meaningful extra columns:**

| Table | Extra Columns | Create Model? |
|-------|---------------|---------------|
| `user_permissions` | `expires_at`, `reason` | ✅ Yes |
| `user_roles` | audit fields only | ❌ No (use `belongsToMany`) |
| `user_departments` | audit fields only | ❌ No |
| `role_permissions` | audit fields only | ❌ No |
| `department_permissions` | audit fields only | ❌ No |

### Model Pattern Example

```php
/**
 * @property string $id
 * @property string $first_name
 * @property bool $is_approved
 * @property \Carbon\CarbonImmutable|null $created_at
 */
final class ProfileModel extends Model
{
    use HasUuids;

    protected $table = 'profiles';
    public $incrementing = false;
    protected $keyType = 'string';
    public $timestamps = false;  // PostgreSQL triggers handle this

    protected function casts(): array
    {
        return [
            'is_approved' => 'boolean',
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    // Relationships defined upfront for documentation
    public function userRole(): HasOne { ... }
    public function departments(): HasManyThrough { ... }
}
```

### Cross-Schema Models

```php
// Models in access schema use qualified table names
protected $table = 'access.roles';
```

---

## Phase 4: Domain Layer Integration (~1-2 hours)

### Value Objects (Domain layer - framework-independent)

```php
// app/Domain/Access/ValueObjects/UserProfile.php
final readonly class UserProfile
{
    public function __construct(
        public string $id,
        public string $firstName,
        public ?string $lastName,
        public bool $isApproved,
        public ?string $roleName,
    ) {}
}
```

### Repository Interfaces (Domain layer)

```php
// app/Domain/Access/Contracts/ProfileRepositoryInterface.php
interface ProfileRepositoryInterface
{
    public function findById(string $userId): ?UserProfile;
}
```

### Repository Implementation (Infrastructure layer)

```php
// app/Infrastructure/Persistence/Repositories/EloquentProfileRepository.php
final readonly class EloquentProfileRepository implements ProfileRepositoryInterface
{
    use WithUserContext;

    public function findById(string $userId): ?UserProfile
    {
        // RLS-protected query - only returns data user can access
        return $this->runAsUser($userId, function () use ($userId) {
            $model = ProfileModel::find($userId);
            return $model ? $this->toDomainObject($model) : null;
        });
    }

    // For admin operations that need service-level access
    public function findAllForAdmin(): array
    {
        return $this->runAsService(fn() => ProfileModel::all()->map(...));
    }
}
```

---

## Phase 5: Service Provider Registration (~30 min)

Register new repositories in `SupabaseServiceProvider` or create new `PersistenceServiceProvider`:

```php
$this->app->bind(ProfileRepositoryInterface::class, EloquentProfileRepository::class);
```

---

## Phase 6: Cutover & Verification (~1 hour)

### Pre-Cutover Checklist
- [ ] All adoption migrations tested locally against Docker PostgreSQL
- [ ] All Eloquent models query correctly
- [ ] Existing `EscalationsConfigRepository` still works

### Cutover Steps
1. Backup production database
2. Run `php artisan migrate --pretend` (dry run)
3. Run `php artisan migrate`
4. Verify frontend still works (RLS intact)
5. Verify Laravel queries work (Eloquent)

### Post-Cutover
- Mark Supabase migrations as "frozen" (add `FROZEN.md`)
- All new schema changes go through Laravel migrations (single source of truth)

---

## RLS Testing Strategy

Create a dedicated integration test suite to verify RLS boundaries work correctly from Laravel.

**Directory:** `tests/Integration/RLS/`

**Purpose:**
- Verify user isolation (users can only access their own data)
- Verify service context can access all data
- Catch regressions if RLS context handling is accidentally broken

**Example tests:**

```php
// tests/Integration/RLS/ProfileRlsTest.php

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->alice = createUserWithProfile('alice@example.com');
    $this->bob = createUserWithProfile('bob@example.com');
});

test('user can only see their own profile via RLS', function () {
    $repository = app(ProfileRepositoryInterface::class);

    // Query as Alice
    $result = $repository->findById($this->alice->id);

    expect($result)->not->toBeNull();
    expect($result->id)->toBe($this->alice->id);
});

test('user cannot see other users profiles via RLS', function () {
    // Set Alice's context
    DB::statement("SELECT set_config('request.jwt.claims', ?, true)", [
        json_encode(['sub' => $this->alice->id])
    ]);

    // Alice tries to query Bob's profile directly
    $result = ProfileModel::find($this->bob->id);

    expect($result)->toBeNull(); // RLS blocks this
});

test('service context can see all profiles', function () {
    // Clear user context (service mode)
    DB::statement("SELECT set_config('request.jwt.claims', '{}', true)");

    $all = ProfileModel::all();

    expect($all)->toHaveCount(2); // Both alice and bob visible
});
```

**Run selectively:**
```bash
php artisan test --filter=RLS
```

---

## Critical Files

### To Modify
- `config/database.php` - Add multi-schema search_path
- `app/Providers/SupabaseServiceProvider.php` - Register new repositories

### To Create
- `database/migrations/` - 15 adoption migrations
- `app/Infrastructure/Persistence/Models/` - ~10 Eloquent models
- `app/Infrastructure/Persistence/Repositories/` - Repository implementations
- `app/Infrastructure/Persistence/Concerns/WithUserContext.php` - RLS session variable trait
- `app/Domain/Access/ValueObjects/` - Domain value objects
- `app/Domain/Access/Contracts/` - Repository interfaces
- `app/Presentation/Http/Middleware/EnsureUserApprovedMiddleware.php` - User approval enforcement
- `tests/Integration/RLS/` - RLS boundary integration tests

### To Audit/Update
- `app/Presentation/Http/Middleware/ValidateSupabaseJwtMiddleware.php` - Add AAL2 check, extract custom claims from `app_metadata`, update `handleLocalBypass()` with configurable claims
- `config/services.php` - Add local test user configuration values
- `.env.example` - Add local bypass configuration examples

### Reference (read-only)
- `/Users/tom/WebstormProjects/alz-admin/supabase/migrations/*.sql` - Source schemas
- `/Users/tom/WebstormProjects/alz-admin/supabase/schema.sql` - Full schema dump

---

## Key Decisions

| Decision | Choice | Why |
|----------|--------|-----|
| Data access pattern | API-first | Frontend calls Laravel API, not Supabase client |
| Eloquent location | `Infrastructure/Persistence/` | Clean Architecture compliance |
| Migration strategy | Adoption (non-destructive) | Preserves existing data |
| RLS policies | Laravel respects via session vars | Defense in depth, same security model |
| `auth.users` FK | Document only | Supabase-managed, can't replicate |
| Future migrations | Laravel only | Single source of truth |
| Local dev | `supabase db reset` + `artisan migrate` | Supabase for Auth, Laravel for adoption |
| Approval enforcement | Separate middleware | SRP, visibility, isolated testing |
| RLS context clearing | Explicit in `runAsService()` | Connection pooling safety |
| RLS testing | Dedicated integration tests | Regression protection for security boundary |
| Local bypass claims | Configurable via `.env` | Flexible dev personas without code changes |
| `user_api_keys` table | Storage only, no API auth | Stores users' third-party keys (ClickUp, etc.) |

---

## Risks & Mitigations

| Risk | Impact | Mitigation |
|------|--------|------------|
| MFA bypass via API | **Critical** | Add AAL2 check to Laravel JWT middleware |
| Missing JWT claims | **Critical** | Audit middleware extracts `is_approved`, `role_name` |
| Unapproved user access | **Critical** | `EnsureUserApprovedMiddleware` rejects `is_approved=false` |
| RLS context leak (connection pooling) | **High** | `runAsService()` explicitly clears context to `{}` |
| Laravel down = Frontend blocked | Medium | Health checks, graceful degradation |
| RLS regression | Medium | Dedicated integration tests in `tests/Integration/RLS/` |
| Eloquent model misconfiguration | Low | Test against local PostgreSQL first |

**Note:** Schema drift between Supabase/Laravel is no longer a risk — after adoption, Laravel is the single source of truth for all schema changes.

---

## Future Considerations

1. **Realtime**: Not currently used - can remove from Supabase config
2. **Storage**: Keep in Supabase for now (just avatars)
3. **Auth**: Indefinite - complex to replace (MFA, Edge Functions)
4. **Full migration away**: Would require replacing Auth (significant effort)

---

## React Frontend Changes (Incremental)

The frontend will need updates as each Laravel API endpoint is built:

| Current Pattern | New Pattern |
|----------------|-------------|
| `supabase.from('profiles').select()` | `fetch('/api/profiles')` |
| `supabase.from('config.dashboard').select()` | `fetch('/api/config/dashboard')` |
| Direct RLS-protected queries | API calls with JWT in header |

**Note**: These changes happen incrementally. Each feature migrates independently as the corresponding Laravel API endpoint is built.
