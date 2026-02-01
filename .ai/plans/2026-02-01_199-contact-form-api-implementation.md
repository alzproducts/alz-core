# Contact Form API Implementation Plan

## Summary

Implement a public API endpoint for contact form submissions that:
1. Stores submissions in PostgreSQL (immutable snapshot)
2. Tracks processing via separate actions table
3. Creates HelpScout conversations via async job
4. Uses honeypot spam protection with silent rejection
5. Applies IP-based rate limiting (5/min)

**Architecture**: Store first → dispatch job → HelpScout creates ticket

**Key Design Decisions:**
- **Two tables**: Immutable submission + mutable action processing (normalized)
- **Tiered schemas**: `public_ingest` (public-writable) + `customer_service` (internal-only)
- **Single UseCase**: Job directly orchestrates HelpScout calls (no second UseCase)
- **Attribution columns**: Flattened for queryability (GCLID needed for future conversion tracking)
- **SDK hybrid**: SDK for writes + auth, direct HTTP for reads (SDK hydration drops fields on reads)

---

## Design Rationale

### Why flatten attribution instead of JSONB?

You mentioned GCLID will be used for "quotes that turn into orders" conversion tracking. This requires:
```sql
-- Future query: find submissions by GCLID to correlate with orders
SELECT * FROM public_ingest.contact_submissions
WHERE gclid = 'CjwKCAjw-abc123';
```

**JSONB limitations:**
- Requires `->` or `->>` operators: `WHERE attribution->>'gclid' = 'CjwKCAjw-abc123'`
- GIN indexes on JSONB are less efficient than B-tree on columns
- No type safety (string vs null ambiguity)

**Flattened columns provide:**
- Direct B-tree indexes on `gclid`, `utm_source`, etc.
- Standard WHERE clauses
- Nullable semantics are explicit
- Better query planner performance

The UTM params are well-defined (fixed schema), so JSONB's flexibility isn't needed.

### Why use SDK for writes but not reads?

**The SDK's problem is entity HYDRATION (parsing responses), not SERIALIZATION (sending requests).**

**For reads** (existing pattern):
- SDK's entity hydration drops fields like `snooze`
- Solution: Direct HTTP, parse responses ourselves
- This is why `HelpScoutHttpTransport` exists

**For writes** (this feature):
- SDK's `conversations()->create()` cleanly serializes our objects
- No hydration - we only get back a conversation ID (int)
- SDK handles the request format, error handling, retries

**Hybrid approach:**
| Operation | Tool | Reason |
|-----------|------|--------|
| Reads | Direct HTTP | SDK hydration loses data |
| Writes | SDK | Clean serialization, no hydration issues |
| Auth | SDK | OAuth2 token management is complex |

**Bonus discovery**: HelpScout API auto-creates customers by email. No need for "find customer → create if not found → create conversation" workflow. Just create conversation with customer email - API handles the rest.

---

## Files to Create

### Domain Layer (`app/Domain/ContactForm/`)

| File | Purpose |
|------|---------|
| `Enums/ContactReason.php` | 9 contact reasons with `isOrderRelated()` helper |
| `Enums/CustomerType.php` | 7 customer types (personal, nhs, care_home, etc.) |
| `Enums/ProductSource.php` | recently_viewed, recently_ordered |
| `Enums/ActionType.php` | helpscout (extensible for future: mixpanel, slack) |
| `Enums/ActionStatus.php` | pending, processing, completed, failed |
| `ValueObjects/ContactFormData.php` | Core form fields (name, email, reason, message, etc.) |
| `ValueObjects/SelectedProduct.php` | Product context (sku, title, price, url) |
| `ValueObjects/ConsentStatus.php` | 4 consent booleans |
| `ValueObjects/MarketingAttribution.php` | GCLID + UTM params (flattened) |
| `ValueObjects/SubmissionContext.php` | page_url, referrer, user_agent, timestamp, IP |
| `ValueObjects/ContactSubmission.php` | Aggregate root combining all above |

### Application Layer (`app/Application/`)

| File | Purpose |
|------|---------|
| `Contracts/ContactForm/ContactSubmissionRepositoryInterface.php` | Repository contract |
| `Contracts/ContactForm/ContactSubmissionActionRepositoryInterface.php` | Action repository contract |
| `ContactForm/UseCases/SubmitContactFormUseCase.php` | Save submission + dispatch job |
| `HelpScout/Commands/CreateConversationCommand.php` | Command DTO for HelpScout |

### Infrastructure Layer (`app/Infrastructure/`)

| File | Purpose |
|------|---------|
| `ContactForm/Models/ContactSubmissionModel.php` | Eloquent model (`public_ingest.contact_submissions`) |
| `ContactForm/Models/ContactSubmissionActionModel.php` | Eloquent model (`customer_service.contact_submission_actions`) |
| `ContactForm/Repositories/EloquentContactSubmissionRepository.php` | Repository impl |
| `ContactForm/Repositories/EloquentContactSubmissionActionRepository.php` | Action repository impl |
| `ContactForm/Mappers/ContactSubmissionMapper.php` | Domain ↔ Model mapping |

### Presentation Layer (`app/Presentation/`)

| File | Purpose |
|------|---------|
| `Http/Controllers/ContactFormController.php` | Invokable controller |
| `Http/Controllers/ContactForm/ContactSubmissionFactory.php` | Request → Domain factory |
| `Http/Requests/ContactFormRequest.php` | Validation rules |
| `Jobs/ContactForm/ProcessContactSubmissionJob.php` | Async HelpScout job (implements ShouldBeUnique) |
| `Jobs/ContactForm/CleanupStaleContactActionsJob.php` | Hourly cleanup for stuck 'processing' records |

### Database

| File | Purpose |
|------|---------|
| `database/migrations/2026_02_01_000001_create_public_ingest_schema.php` | Create schema |
| `database/migrations/2026_02_01_000002_create_public_ingest_contact_submissions_table.php` | Submissions table |
| `database/migrations/2026_02_01_000003_create_customer_service_contact_submission_actions_table.php` | Actions table |

---

## Files to Modify

| File | Change |
|------|--------|
| `app/Application/Contracts/HelpScout/ConversationsClientInterface.php` | Add `createConversation()` method |
| `app/Infrastructure/HelpScout/Clients/ConversationsClient.php` | Implement `createConversation()` using SDK |
| `routes/api.php` | Add public `/contact` route |
| `app/Providers/RateLimitServiceProvider.php` | Add `contact-form` rate limiter |
| `app/Providers/AppServiceProvider.php` | Bind repository interfaces |

**Note**: No changes needed to `HelpScoutHttpTransport` - SDK handles writes directly via `conversations()->create()`.

---

## Database Schema

### Table 1: `public_ingest.contact_submissions` (immutable snapshot)

```sql
-- Migration: create_public_ingest_contact_submissions_table.php
CREATE SCHEMA IF NOT EXISTS public_ingest;

CREATE TABLE public_ingest.contact_submissions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Core form (flattened for querying)
    name VARCHAR(255) NOT NULL,
    email VARCHAR(255) NOT NULL,
    reason VARCHAR(50) NOT NULL,
    message TEXT NOT NULL,
    phone VARCHAR(50),
    customer_type VARCHAR(50),
    order_number VARCHAR(20),
    delivery_postcode VARCHAR(20),
    quantity SMALLINT,

    -- Product context (JSONB - optional, variable structure)
    product JSONB,

    -- User identification
    shopwired_customer_id VARCHAR(50),

    -- Consent (separate columns for compliance filtering)
    consent_marketing BOOLEAN NOT NULL DEFAULT false,
    consent_statistics BOOLEAN NOT NULL DEFAULT false,
    consent_preferences BOOLEAN NOT NULL DEFAULT false,
    consent_has_responded BOOLEAN NOT NULL DEFAULT false,

    -- Attribution (separate columns for queryability - needed for conversion tracking)
    gclid VARCHAR(255),           -- Google Ads click ID
    utm_source VARCHAR(255),
    utm_medium VARCHAR(255),
    utm_campaign VARCHAR(255),
    utm_content VARCHAR(255),
    utm_term VARCHAR(255),

    -- Context
    page_url TEXT NOT NULL,
    referrer_url TEXT,
    user_agent TEXT,
    client_timestamp TIMESTAMPTZ NOT NULL,
    ip_address INET NOT NULL,

    -- Timestamp (immutable - no updated_at)
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- Comments for internal columns
COMMENT ON COLUMN public_ingest.contact_submissions.ip_address IS 'Internal: captured for rate limiting and fraud detection';
COMMENT ON COLUMN public_ingest.contact_submissions.gclid IS 'Internal: Google Ads click ID for conversion attribution';
COMMENT ON COLUMN public_ingest.contact_submissions.consent_marketing IS 'Consent status at submission time for compliance audit';

-- Indexes
CREATE INDEX idx_contact_submissions_email ON public_ingest.contact_submissions(email);
CREATE INDEX idx_contact_submissions_reason ON public_ingest.contact_submissions(reason);
CREATE INDEX idx_contact_submissions_created_at ON public_ingest.contact_submissions(created_at);
CREATE INDEX idx_contact_submissions_gclid ON public_ingest.contact_submissions(gclid) WHERE gclid IS NOT NULL;
CREATE INDEX idx_contact_submissions_order_number ON public_ingest.contact_submissions(order_number) WHERE order_number IS NOT NULL;
```

### Table 2: `customer_service.contact_submission_actions` (mutable processing state)

```sql
-- Migration: create_customer_service_contact_submission_actions_table.php
CREATE SCHEMA IF NOT EXISTS customer_service;

CREATE TABLE customer_service.contact_submission_actions (
    id UUID PRIMARY KEY DEFAULT gen_random_uuid(),

    -- Foreign key to submission (cascade delete for GDPR erasure)
    contact_submission_id UUID NOT NULL REFERENCES public_ingest.contact_submissions(id) ON DELETE CASCADE,

    -- Action type (extensible for future actions)
    action_type VARCHAR(50) NOT NULL,  -- 'helpscout', 'mixpanel', 'slack', etc.

    -- Processing state
    status VARCHAR(20) NOT NULL DEFAULT 'pending',  -- pending, processing, completed, failed

    -- External reference (e.g., HelpScout conversation ID)
    external_id VARCHAR(255),

    -- Error tracking
    error_message TEXT,
    attempts INTEGER NOT NULL DEFAULT 0,

    -- Timestamps
    created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    processing_started_at TIMESTAMPTZ,  -- For stale detection
    completed_at TIMESTAMPTZ
);

-- Indexes
CREATE INDEX idx_csa_submission_id ON customer_service.contact_submission_actions(contact_submission_id);
CREATE INDEX idx_csa_status ON customer_service.contact_submission_actions(status);
CREATE INDEX idx_csa_pending ON customer_service.contact_submission_actions(status, action_type)
    WHERE status = 'pending';
CREATE INDEX idx_csa_stale_processing ON customer_service.contact_submission_actions(processing_started_at)
    WHERE status = 'processing';

-- Unique constraint: one action per type per submission
CREATE UNIQUE INDEX idx_csa_unique_action ON customer_service.contact_submission_actions(contact_submission_id, action_type);
```

---

## HelpScout Integration

### Create Conversation via SDK

Use the SDK's `conversations()->create()` method - it's clean for writes:

```php
use HelpScout\Api\Customers\Customer;
use HelpScout\Api\Conversations\Conversation;
use HelpScout\Api\Conversations\Threads\CustomerThread;
use HelpScout\Api\Entity\Collection;  // Use SDK's Collection, not Illuminate

$customer = new Customer();
$customer->addEmail($submission->form->email);
$customer->setFirstName($this->extractFirstName($submission->form->name));
$customer->setLastName($this->extractLastName($submission->form->name));

$thread = new CustomerThread();
$thread->setCustomer($customer);
$thread->setText($this->buildMessageBody($submission));

$conversation = new Conversation();
$conversation->setMailboxId($this->config->getMailboxId('support'));
$conversation->setType('email');
$conversation->setSubject($this->buildSubject($submission));
$conversation->setStatus('active');
$conversation->setCustomer($customer);
$conversation->setThreads(new Collection([$thread]));

// Tags: Use addTag() method, not setTags with Collection
foreach ($this->determineTags($submission) as $tag) {
    $conversation->addTag($tag);  // SDK handles Tag object creation
}

$conversationId = $this->client->conversations()->create($conversation);
```

**Key behavior**: HelpScout **auto-creates customers** by email. No need for "find or create customer" logic.

### SDK Usage Clarification

| Operation | Uses |
|-----------|------|
| **Reads** (get conversations) | Direct HTTP - SDK hydration drops fields like `snooze` |
| **Writes** (create conversation) | SDK - clean serialization, no hydration issues |
| **Auth** | SDK - OAuth2 client credentials, token refresh |

This hybrid approach gives us the best of both worlds:
- Reliable auth handling via SDK
- Full read control via direct HTTP
- Simple write API via SDK

---

## Request/Response Flow

```
POST /api/contact
├── Rate limit check (5/min per IP)
├── Validation (ContactFormRequest)
├── Honeypot check
│   └── If triggered → return 200 (silent rejection)
├── Build ContactSubmission from request
├── SubmitContactFormUseCase::execute()
│   ├── Save to public_ingest.contact_submissions (immutable)
│   ├── Create customer_service.contact_submission_actions (status: pending)
│   └── Dispatch ProcessContactSubmissionJob
└── Return 200 with reference ID

[Async - Queue Worker]
ProcessContactSubmissionJob::handle()
├── Load submission + action from DB
├── Update action status → 'processing'
├── Build HelpScout conversation body
├── POST to HelpScout API via transport
├── Update action → 'completed' + external_id
└── On failure: retry with backoff [60s, 5min, 1hr, 12hr]
```

---

## Error Handling

| Scenario | HTTP Response | Job Behavior |
|----------|---------------|--------------|
| Validation error | 422 + field errors | N/A |
| Rate limit exceeded | 429 | N/A |
| Honeypot triggered | 200 (silent) | N/A |
| DB unavailable | 500 | N/A (pre-job) |
| HelpScout unavailable | N/A | Retry with backoff (transient) |
| HelpScout auth expired | N/A | Fail permanently, log CRITICAL |
| Invalid HelpScout response | N/A | Fail permanently, needs code fix |

**Action Status Updates:**
- On transient failure: status stays 'processing', attempts++
- On permanent failure: status → 'failed', error_message set
- On success: status → 'completed', external_id set, completed_at set

---

## Rate Limiting

```php
// RateLimitServiceProvider.php
RateLimiter::for('contact-form', static fn(Request $request): Limit =>
    Limit::perMinute(5)->by($request->ip())
);
```

---

## CORS Configuration

Create `config/cors.php` to allow cross-origin requests from ShopWired:

```php
// config/cors.php
return [
    'paths' => ['api/contact'],  // Only enable for contact endpoint
    'allowed_methods' => ['POST', 'OPTIONS'],
    'allowed_origins' => [
        'https://www.alzproducts.co.uk',
        'https://alzproducts.co.uk',
        // Add staging/dev domains as needed
    ],
    'allowed_origins_patterns' => [],
    'allowed_headers' => ['Content-Type', 'Accept', 'X-Requested-With'],
    'exposed_headers' => [],
    'max_age' => 86400,  // 24 hours
    'supports_credentials' => false,
];
```

**Note**: Register `\Illuminate\Http\Middleware\HandleCors::class` in middleware stack if not already present.

---

## Job Configuration

```php
// ProcessContactSubmissionJob.php
final class ProcessContactSubmissionJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 4;
    public array $backoff = [60, 300, 3600, 43200];  // 1min, 5min, 1hr, 12hr
    public int $timeout = 60;

    public function __construct(
        private readonly string $submissionId,
        private readonly string $actionId,
    ) {
        $this->onQueue('default');
    }

    // Prevent duplicate processing of same submission
    public function uniqueId(): string
    {
        return $this->submissionId;
    }
}
```

**Key patterns:**
- `ShouldBeUnique` by submission ID prevents duplicate HelpScout tickets on retry
- Follow existing job patterns (see `UpdateSkuJob` for exception handling)
- End with `catch (\Throwable)` that logs critical, calls `$this->fail()`, rethrows

**Retry rationale:**
- 60s: Quick retry for transient network issues
- 5min: Brief service interruption
- 1hr: Medium outage (maintenance windows)
- 12hr: Major outage (gives time for incident resolution)

---

## Stale Processing Cleanup

Add a scheduled job to handle stuck 'processing' records:

```php
// CleanupStaleContactActionsJob.php (runs hourly via scheduler)
final class CleanupStaleContactActionsJob implements ShouldQueue
{
    public function handle(ContactSubmissionActionRepositoryInterface $repository): void
    {
        // Find actions stuck in 'processing' for > 1 hour
        $staleActions = $repository->findStaleProcessing(hours: 1);

        foreach ($staleActions as $action) {
            Log::warning('Resetting stale contact action', [
                'action_id' => $action->id,
                'submission_id' => $action->contactSubmissionId,
            ]);

            // Reset status AND re-dispatch job
            $repository->resetToPending($action->id);
            ProcessContactSubmissionJob::dispatch($action->contactSubmissionId, $action->id);
        }
    }
}
```

**Scheduler registration** (`routes/console.php`):
```php
Schedule::job(new CleanupStaleContactActionsJob)->hourly();
```

---

## Verification Plan

### Manual Testing

1. **Happy path**: Submit valid form → verify DB record → verify HelpScout ticket created
2. **Honeypot**: Submit with non-empty `spam.honeypot_value` → verify 200 response, no DB record
3. **Rate limiting**: Submit 6 requests rapidly → verify 429 on 6th
4. **Validation**: Submit with missing required fields → verify 422 with errors

### Automated Tests

1. **Unit**: Domain value objects with invalid data (assertions)
2. **Unit**: ContactSubmissionFactory mapping
3. **Feature**: ContactFormController with mocked repository
4. **Integration**: Full flow with test database (no HelpScout)
5. **Integration**: HelpScout client with mocked HTTP (VCR or Http::fake)

### HelpScout Verification

1. Check HelpScout inbox for test ticket
2. Verify subject format: `[Reason] Web Contact Form` or `[Reason] Order A12345`
3. Verify body includes: message, product details (if any), customer type, attribution
4. Verify tags applied: `web-form` + reason-specific tags

---

## Implementation Order

1. **Database**: Create schemas + migrations (public_ingest, customer_service tables)
2. **Domain**: Enums and value objects (pure PHP, no dependencies)
3. **Infrastructure - Repository**: Models (with schema-qualified table names), mappers, repositories
4. **Infrastructure - HelpScout**: Implement `createConversation()` using SDK in client
5. **Application**: Single UseCase (SubmitContactFormUseCase)
6. **Presentation**: FormRequest, Factory, Controller, Job, Cleanup Job
7. **Routing**: Add route, rate limiter, and CORS config
8. **Scheduler**: Register cleanup job
9. **Testing**: Write tests for each layer
10. **Integration test**: End-to-end with real HelpScout (sandbox or test mailbox)

---

## Future Extensibility Notes

The two-table design with `action_type` column supports adding more actions:

**To add a new action (e.g., Mixpanel tracking):**
1. Add `ActionType::Mixpanel` enum value
2. Create new job `ProcessContactSubmissionMixpanelJob`
3. In `SubmitContactFormUseCase`, insert additional action row
4. Dispatch the new job alongside HelpScout job

Each action has independent status tracking, retries, and error handling.

**Other extensibility:**
- **Routing by reason**: Different HelpScout mailboxes based on `ContactReason`
- **Events**: Dispatch `ContactFormSubmitted` event for loose coupling
- **Webhooks**: Notify external systems on submission
