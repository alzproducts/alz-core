# Plan: Migrate HelpScout API from Frontend to Backend

## Overview

Move HelpScout API integration from React frontend (`alz-admin`) to Laravel backend (`alz-core`), following Clean Architecture patterns. Backend transforms data for frontend consumption.

---

## SDK vs Direct HTTP Decision

**Issue**: The `helpscout/api-laravel` SDK doesn't support the `snooze` field (silently dropped during hydration), but the HelpScout API does return it.

**Decision**: **HTTP for conversations, SDK available for future features**.

**Architecture**:
```php
final class HelpScoutClient implements HelpScoutClientInterface
{
    public function __construct(
        private HelpScoutHttpTransport $http,  // Direct HTTP (snooze support)
        private ApiClient $sdk,                 // SDK (injected, ready for future)
    ) {}
}
```

**Rationale**:
- All 4 current widgets need `snooze` → use direct HTTP
- SDK remains injected for future features (customers, threads, notes) where snooze is irrelevant
- Shared OAuth2 tokens - both approaches use same credentials
- Application layer is abstracted - doesn't know/care which is used internally
- No wasted work - can add SDK-based methods later without refactoring

---

## Frontend Requirements (What We're Serving)

The dashboard has **4 widgets** that need HelpScout data:

| Widget             | Query Logic                          | Key Params                                        |
|--------------------|--------------------------------------|---------------------------------------------------|
| User Conversations | Assigned to current user             | `status: active,pending`, `assigned_to: {hs_user_id}` |
| User To-Dos        | Tagged to-do for user                | `tag: 'server to-do'`, `assigned_to: {hs_user_id}` |
| Negative Reviews   | Negative feedback tag                | `tag: 'feedback-review-negative'`, `status: active` |
| Escalations        | Late priority/standard conversations | Multiple queries with `waitingSince` thresholds   |

**Critical Data Fields**: `customerWaitingSince`, `tags`, `assignee`, `snooze`, `primaryCustomer`, `mailboxId`

---

## API Endpoints

All endpoints protected by `ValidateSupabaseJwtMiddleware` (existing).

| Endpoint                                          | Description                     | Cache TTL |
|---------------------------------------------------|---------------------------------|-----------|
| `GET /api/helpscout/conversations/assigned`       | User's assigned conversations   | 5 min     |
| `GET /api/helpscout/conversations/todos`          | User's to-do conversations      | 5 min     |
| `GET /api/helpscout/conversations/negative-reviews` | Negative feedback conversations | 5 min   |
| `GET /api/helpscout/escalations`                  | Late/escalated conversations    | 5 min     |
| `GET /api/helpscout/mailboxes`                    | All mailboxes                   | 7 days    |

---

## Architecture

```
Presentation
└── HelpScoutController.php          # REST endpoints

Application
├── Contracts/
│   ├── HelpScoutClientInterface.php # Interface for DI
│   └── EscalationsConfigRepositoryInterface.php
├── HelpScout/
│   ├── CachingHelpScoutService.php  # Cache decorator + user mapping
│   └── DTOs/                        # Response DTOs (snake_case output)

Infrastructure
├── HelpScout/
│   ├── HelpScoutConfig.php          # API config (base URL, credentials)
│   ├── HelpScoutHttpTransport.php   # Direct HTTP via Laravel Http facade
│   ├── HelpScoutClient.php          # Business logic, implements interface
│   ├── HelpScoutClientFactory.php   # Wires components
│   └── DTOs/                        # API response parsing (Spatie Data)
└── Supabase/
    └── EscalationsConfigRepository.php  # Queries config.dashboard

Domain
└── HelpScout/
    └── EscalationsConfig.php        # Value object for escalation settings
```

**Note**: Using direct HTTP instead of SDK wrapper for full `snooze` field support.

---

## Type Safety Strategy

**Create our own Spatie Data DTOs** - Don't extend SDK entities or use `@var array`.

**Infrastructure DTOs** (parse HelpScout JSON):
```php
#[MapInputName(SnakeCaseMapper::class)]
final class ConversationData extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $subject,
        public readonly ?SnoozeData $snooze,  // ✅ Full snooze support
        // ... all fields we need
    ) {}
}
```

**Application DTOs** (format for API response):
```php
#[MapOutputName(SnakeCaseMapper::class)]
final class ConversationForApiDTO extends Data
{
    public static function fromInfrastructure(ConversationData $conv): self
    {
        return new self(
            id: $conv->id,
            snoozedUntil: $conv->snooze?->snoozeUntil,
            // ... transform as needed
        );
    }
}
```

**Benefits**:
- Full IDE autocomplete and PHPStan validation
- Decoupled from SDK - we control the structure
- Explicit field mapping between layers
- `snooze` field fully supported

---

## OAuth2 Token Management

**Reuse SDK's authenticator** for all HTTP requests - single token lifecycle, no duplication.

```php
// HelpScoutHttpTransport uses SDK's authenticator:
$token = HelpScout::getAuthenticator()->getAuthHeader();
return Http::withHeader('Authorization', $token)->get($url, $params);
```

**Benefits**:
- SDK handles token refresh automatically (via `helpscout/api-laravel` ServiceProvider)
- Single token lifecycle - no conflicts between SDK and HTTP calls
- Hybrid-ready - when we add SDK methods later, they share the same token
- Less code to maintain - no custom OAuth2 implementation

---

## User Mapping Strategy

**Approach**: Query HelpScout API by email, cache result with long TTL.

```php
// CachingHelpScoutService::resolveHelpScoutUserId(string $supabaseEmail)
// 1. Check cache: "helpscout:user-mapping:{normalized_email}"
// 2. If miss: Query HelpScout /users API, find by email (CASE-INSENSITIVE)
// 3. Cache mapping with 7-day TTL
// 4. Return HelpScout user ID (or throw UserNotLinkedToHelpScoutException)
```

**Important details**:
- **Case-insensitive matching**: Normalize email to lowercase before lookup
- **Only required for 2 endpoints**: `assigned` and `todos` need HelpScout user ID
- **Other endpoints work without mapping**: `escalations`, `negative-reviews`, `mailboxes`
- **Clear error message**: "Your email (X) is not linked to a HelpScout account"

**Benefits**: No config maintenance, self-healing, low latency after first lookup.

---

## Caching Strategy

Using existing `GracefulCache` wrapper with `CacheTimesTrait` constants.

| Data                        | Cache Key                      | TTL    |
|-----------------------------|--------------------------------|--------|
| User mapping                | `helpscout:user-mapping:{email}` | 7 days |
| Escalation config           | `helpscout:escalation-config`  | 5 min  |
| Conversations (all queries) | `helpscout:conv:{query_hash}`  | 5 min  |
| Mailboxes                   | `helpscout:mailboxes`          | 7 days |

---

## Escalation Config from Supabase

**Config stored in Supabase** (shared PostgreSQL): `config.dashboard` table, `table_name = 'hs_escalations'`

```php
// EscalationsConfig schema (from Supabase settings JSONB column)
[
    'lateThresholdHours' => 24,           // Standard conversations threshold
    'latePriorityThresholdHours' => 4,    // Priority conversations threshold
    'priorityTags' => ['urgent', 'vip'],  // Tags marking priority
    'excludedTags' => ['on-hold'],        // Tags to exclude
    'assignedTag' => 'assigned',          // Manually assigned tag
]
```

**Backend approach:**
```php
// Infrastructure/Supabase/EscalationsConfigRepository.php
DB::table('config.dashboard')
    ->where('table_name', 'hs_escalations')
    ->where('enabled', true)
    ->value('settings');  // Returns JSONB as array
```

1. Query via Laravel DB facade with `config` schema prefix
2. Cache config with **5 min TTL** (user-editable, needs to reflect changes reasonably fast)
3. Parse JSONB `settings` column into Domain value object
4. Use config values to build HelpScout queries

---

## Escalations Logic

The escalations widget requires multiple HelpScout queries:

```php
// For each monitored mailbox (SUPPORT, PURCHASE_ORDERS):
//   1. Priority late: mailboxid:{id} AND tag:{priorityTag} AND waitingSince:[* TO NOW-{latePriorityThresholdHours}HOUR]
//   2. Standard late: mailboxid:{id} AND NOT tag:{priorityTag} AND waitingSince:[* TO NOW-{lateThresholdHours}HOUR]
//   3. Manually assigned: tag:{assignedTag}
// Deduplicate results, sort by priority hierarchy
```

---

## Files to Create

### Infrastructure Layer
- `app/Infrastructure/HelpScout/HelpScoutConfig.php` - API config (base URL, timeout, retry)
- `app/Infrastructure/HelpScout/HelpScoutHttpTransport.php` - Direct HTTP via Laravel Http facade
- `app/Infrastructure/HelpScout/HelpScoutClient.php` - Business logic, query building
- `app/Infrastructure/HelpScout/HelpScoutClientFactory.php` - Wires components
- `app/Infrastructure/HelpScout/DTOs/ConversationData.php` - Parse API JSON response
- `app/Infrastructure/HelpScout/DTOs/CustomerData.php`
- `app/Infrastructure/HelpScout/DTOs/AssigneeData.php`
- `app/Infrastructure/HelpScout/DTOs/TagData.php`
- `app/Infrastructure/HelpScout/DTOs/SnoozeData.php` - **Snooze field support**
- `app/Infrastructure/HelpScout/DTOs/MailboxData.php`
- `app/Infrastructure/HelpScout/DTOs/UserData.php` - For user mapping lookup
- `app/Infrastructure/Supabase/EscalationsConfigRepository.php` - Queries `config.dashboard` table

### Domain Layer
- `app/Domain/HelpScout/EscalationsConfig.php` - Value object for escalation settings
- `app/Domain/Exceptions/UserNotLinkedToHelpScoutException.php` - Clear error for unmapped users

### Application Layer
- `app/Application/Contracts/HelpScoutClientInterface.php`
- `app/Application/Contracts/EscalationsConfigRepositoryInterface.php`
- `app/Application/HelpScout/CachingHelpScoutService.php` - Cache + user mapping
- `app/Application/HelpScout/DTOs/ConversationForApiDTO.php` - Response with snake_case
- `app/Application/HelpScout/DTOs/MailboxForApiDTO.php`

### Presentation Layer
- `app/Presentation/Http/Controllers/HelpScoutController.php`

### Provider & Config
- `app/Providers/HelpScoutAppServiceProvider.php`

### Tests
- `tests/Unit/Infrastructure/HelpScout/HelpScoutConfigTest.php`
- `tests/Unit/Infrastructure/HelpScout/HelpScoutTransportTest.php`
- `tests/Unit/Infrastructure/HelpScout/HelpScoutClientTest.php`
- `tests/Unit/Application/HelpScout/CachingHelpScoutServiceTest.php`
- `tests/Feature/HelpScout/HelpScoutEndpointsTest.php`

---

## Files to Modify

- `config/helpscout.php` - Add monitored mailboxes, local test email
- `.env.example` - Add new env vars
- `routes/api.php` - Add HelpScout route group
- `bootstrap/providers.php` - Register service provider
- `deptrac.yaml` - Allow HelpScout SDK in Infrastructure layer
- `app/Presentation/Http/Middleware/ValidateSupabaseJwtMiddleware.php` - Add local testing bypass

---

## Config Additions

```php
// config/helpscout.php additions
'monitored_mailboxes' => [
    'support' => (int) env('HS_MAILBOX_SUPPORT'),
    'purchase_orders' => (int) env('HS_MAILBOX_PURCHASE_ORDERS'),
],
```

**Note**: Escalation thresholds (`lateThresholdHours`, `latePriorityThresholdHours`, `priorityTags`, etc.) come from **Supabase** `config.dashboard` table, not env vars. Only mailbox IDs need env config.

---

## Response Format

```json
{
  "data": [{
    "id": 123456,
    "number": 789,
    "subject": "Order issue",
    "status": "active",
    "mailbox_id": 12345,
    "primary_customer": {
      "first_name": "John",
      "last_name": "Doe",
      "email": "john@example.com"
    },
    "assignee": {
      "id": 456,
      "first_name": "Jane",
      "photo_url": "https://..."
    },
    "tags": [{"id": 1, "tag": "urgent", "color": "#FF0000"}],
    "customer_waiting_since": "2025-01-15T10:30:00Z",
    "snoozed_until": null,
    "created_at": "2025-01-14T09:00:00Z",
    "updated_at": "2025-01-15T11:00:00Z"
  }],
  "meta": {"total": 25, "page": 1, "pages": 3}
}
```

---

## Implementation Order

1. **Infrastructure Foundation** - Config, Transport, Client, Factory, DTOs
2. **Application Layer** - Interface, CachingService, Response DTOs
3. **Service Provider** - Bindings and registration
4. **Presentation** - Controller and routes
5. **Configuration** - Extend helpscout.php, env vars
6. **Testing** - Unit and feature tests

---

## Reference Files

**Backend patterns:**
- `app/Infrastructure/BingAds/BingAdsTransport.php` - SDK wrapping pattern
- `app/Application/Support/GracefulCache.php` - Caching utility
- `app/Application/Support/CacheTimesTrait.php` - TTL constants
- `app/Presentation/Http/Middleware/ValidateSupabaseJwtMiddleware.php` - Auth pattern
- `vendor/helpscout/api/src/Conversations/ConversationsEndpoint.php` - SDK interface

**Frontend reference (React implementation):**
- `/Users/tom/WebstormProjects/alz-admin/src/services/apis/helpscout/` - Full HelpScout integration
- `/Users/tom/WebstormProjects/alz-admin/src/services/apis/helpscout/schemas.ts` - **Source of truth for DTOs**

⚠️ **Important**: HelpScout API documentation has schema inaccuracies. The React schemas were smoke-tested and corrected during frontend implementation.

**DTO Schema Source of Truth**:
1. **Primary**: React schemas (`schemas.ts`) - tested against real API responses
2. **Cross-reference**: PHP SDK entities (`vendor/helpscout/api/src/`) - sanity check field names/types
3. **If they disagree**: Trust React (it was smoke-tested)

Note: SDK is incomplete (missing `snooze`), so it cannot be sole source of truth.

---

## Local Testing Strategy

Add environment-based auth bypass to test endpoints without Supabase JWT.

**Modify `ValidateSupabaseJwtMiddleware`**:
```php
public function handle(Request $request, Closure $next): Response
{
    // Local testing bypass
    if (app()->environment('local') && $request->hasHeader('X-Local-Bypass')) {
        $request->attributes->set('supabase_user', [
            'id' => 'local-test-user',
            'email' => config('helpscout.local_test_email', 'test@example.com'),
        ]);
        return $next($request);
    }

    // Normal JWT validation...
}
```

**Usage**:
```bash
curl -H "X-Local-Bypass: 1" http://localhost:8000/api/helpscout/conversations/assigned
```

**Config addition** (`config/helpscout.php`):
```php
'local_test_email' => env('HS_LOCAL_TEST_EMAIL', 'tom@example.com'),
```

This allows testing the full flow locally with a configurable test email for user mapping.
