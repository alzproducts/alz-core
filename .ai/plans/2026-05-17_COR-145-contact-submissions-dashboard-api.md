# COR-145: Contact Submissions Dashboard API (List + Annotations)

## Context

Staff-facing dashboard needs API endpoints to list contact submissions with enriched data (lead/quote statuses, annotations) and update annotations. This is the largest sub-issue of COR-143 (Contact Submissions Dashboard), requiring a 3-table join across `public_ingest`, `customer_service`, and `marketing` schemas.

**Foundation from COR-144 (already on develop):**
- `marketing` schema + `marketing.contact_submission_annotations` table (UUID PK, FK to contact_submissions, `is_potential_quote`, `notes`, `quoted_at`, unique on `contact_submission_id`)
- `ActionType` enum with `LeadReceived`, `QuoteIssued` cases
- Existing `ContactSubmissionModel` + `ContactSubmissionActionModel`

**Branch:** `feature/cor-143b-contact-submissions-api`

**Design decisions (resolved):**
- `date_to` filter is **inclusive end-of-day** — DTO translates `Y-m-d` to `< (date_to + 1 day)` half-open interval
- Annotation PUT uses **partial-patch semantics** via Spatie `Optional` — only sent fields are updated
- Submission existence verified via a new lightweight `existsById()` method on `ContactSubmissionRepositoryInterface`
- `ConversionStatus` cases are **orthogonal facts** (a submission may be both lead_sent and quote_sent); filter is single-select for clarity

---

## Implementation Plan

### Phase 1: Domain Layer (3 files)

#### 1. `app/Domain/ContactSubmission/Enums/ConversionStatus.php`

```php
enum ConversionStatus: string
{
    case None = 'none';
    case LeadPending = 'lead_pending';
    case LeadSent = 'lead_sent';
    case QuotePending = 'quote_pending';
    case QuoteSent = 'quote_sent';
}
```

Each case is an independent EXISTS predicate against the actions table. A submission may match multiple states; the filter is single-select.

#### 2. `app/Domain/ContactSubmission/Enums/ContactSubmissionFilterField.php`

```php
enum ContactSubmissionFilterField: string
{
    case HasGclid = 'has_gclid';
    case IsPotentialQuote = 'is_potential_quote';
    case DateFrom = 'date_from';
    case DateTo = 'date_to';
    case ConversionStatus = 'conversion_status';
}
```

#### 3. `app/Domain/ContactSubmission/ValueObjects/ContactSubmissionListItem.php`

Flattened VO aggregating fields from all 3 tables:

- From `public_ingest.contact_submissions`: `id` (Guid), `name`, `email`, `reason` (ContactReason), `customerType` (?CustomerType), `orderNumber`, `quantity`, `product` (?array), `shopwiredCustomerId`, `gclid`, `msclkid`, `fbclid`, `utmSource`, `utmMedium`, `utmCampaign`, `pageUrl`, `createdAt` (DateTimeImmutable)
- From `customer_service.contact_submission_actions`: `helpscoutExternalId` (?string), `leadStatus` (?ActionStatus), `quoteStatus` (?ActionStatus)
- From `marketing.contact_submission_annotations`: `isPotentialQuote` (?bool), `notes` (?string), `quotedAt` (?DateTimeImmutable)

---

### Phase 2: Application Layer (6 files; 1 modified)

#### 4. `app/Application/ContactSubmission/Queries/ContactSubmissionListQueryParams.php`

```php
final readonly class ContactSubmissionListQueryParams
{
    /** @param array<value-of<ContactSubmissionFilterField>, mixed> $filters */
    public function __construct(
        public PageRequest $pagination,
        public array $filters = [],
    ) {}
}
```

No sort params (always `created_at desc`).

#### 5. `app/Application/Contracts/ContactSubmission/ContactSubmissionDashboardQueryRepositoryInterface.php`

Follows `*QueryRepositoryInterface.php` convention. Single method:

```php
/**
 * @return PaginatedList<ContactSubmissionListItem>
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 */
public function paginate(ContactSubmissionListQueryParams $query): PaginatedList;
```

#### 6. `app/Application/Contracts/ContactSubmission/ContactSubmissionAnnotationRepositoryInterface.php`

```php
public function upsert(UpsertAnnotationCommand $command): void;
```

Same `@throws` as above. Accepts the command (carries Optional-aware payload, not raw nullables).

#### 7. `app/Application/Contracts/ContactSubmission/ContactSubmissionRepositoryInterface.php` (MODIFIED)

Add a lightweight existence check:

```php
/**
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 */
public function existsById(string $id): bool;
```

Implementation in `EloquentContactSubmissionRepository` uses `$this->eloquentGateway->exists(ContactSubmissionModel::class, 'id', $id)`.

#### 8. `app/Application/ContactSubmission/Commands/UpsertAnnotationCommand.php`

Partial-patch shape: each field is `T | UnsetValue` so the repository knows which columns to write.

```php
final readonly class UpsertAnnotationCommand
{
    public function __construct(
        public string $contactSubmissionId,
        public bool|null|UnsetValue $isPotentialQuote,
        public string|null|UnsetValue $notes,
        public DateTimeImmutable|null|UnsetValue $quotedAt,
    ) {}

    /** @return array<string, mixed> Map of only the columns to write */
    public function toUpdateAttributes(): array { /* skip UnsetValue entries */ }
}
```

`UnsetValue` is a domain-layer sentinel singleton (see existing `App\Domain\ValueObjects` — if no equivalent exists, create one or use a simple `enum UnsetValue { case Instance; }`). Verify before implementing; if the project has an established sentinel pattern for merge-patch commands per `.claude/rules/application-commands.md`, reuse it.

#### 9. `app/Application/ContactSubmission/UseCases/ListContactSubmissionsUseCase.php`

Thin orchestrator: log → `$dashboardQuery->paginate($query)` → log → return.

#### 10. `app/Application/ContactSubmission/UseCases/UpsertContactSubmissionAnnotationUseCase.php`

```
1. if (!$submissionRepository->existsById($command->contactSubmissionId))
       throw new RecordNotFoundException('ContactSubmission', $command->contactSubmissionId);
2. $annotationRepository->upsert($command);
3. log success
```

`@throws`: `RecordNotFoundException`, `DatabaseOperationFailedException`, `DuplicateRecordException`, `ExternalServiceUnavailableException`.

---

### Phase 3: Infrastructure Layer (3 files; 1 modified)

#### 11. `app/Infrastructure/Marketing/Models/ContactSubmissionAnnotationModel.php`

Standard Eloquent model: `final class`, `HasUuids`, `$table = 'marketing.contact_submission_annotations'`, `$guarded = []`, casts via `#[Override] casts()` for `is_potential_quote` (boolean), `quoted_at` (immutable_datetime), `created_at`/`updated_at` (immutable_datetime).

#### 12. `app/Infrastructure/Marketing/Repositories/EloquentContactSubmissionAnnotationRepository.php`

`final readonly class` injecting `EloquentGateway`. The upsert builds attributes from `$command->toUpdateAttributes()` (only sent fields). Uses `$this->eloquentGateway->upsertOne()` with `uniqueBy: ['contact_submission_id']` and explicit `update` columns from the command's `toUpdateAttributes()` keys — this ensures unsent fields preserve their existing values on conflict.

Note: `upsertOne()` calls `computeUpdateColumns(..., $explicitUpdate)`. Pass an explicit list so only patched columns participate in `ON CONFLICT DO UPDATE`.

#### 13. `app/Infrastructure/Marketing/Repositories/EloquentContactSubmissionDashboardQueryRepository.php`

**Key complexity file.** Uses `EloquentGateway::paginate()` with a custom scope.

**Query strategy:**
- Primary model: `ContactSubmissionModel` (table `public_ingest.contact_submissions`)
- **LEFT JOIN** `marketing.contact_submission_annotations as annot` (1:1 via unique index — safe)
- **Correlated subqueries** for the 3 action status/external_id values (avoids row multiplication from 1:N actions)

**Scope closure (no `DB::` facade — uses Builder methods on `$q`):**
```
$q->select('public_ingest.contact_submissions.*')
  ->selectSub(fn($s) => $s->from('customer_service.contact_submission_actions')
      ->select('status')->whereColumn(...)->where('action_type','lead_received')->limit(1), 'lead_status')
  ->selectSub(... 'quote_status')
  ->selectSub(... 'helpscout_external_id')
  ->leftJoin('marketing.contact_submission_annotations as annot',
      'annot.contact_submission_id', '=', 'public_ingest.contact_submissions.id')
  ->addSelect(['annot.is_potential_quote as annot_is_potential_quote',
               'annot.notes as annot_notes',
               'annot.quoted_at as annot_quoted_at'])
  ->orderByDesc('public_ingest.contact_submissions.created_at');
```

Aliases on the joined annot columns avoid attribute collision with model casts.

**Filter implementation:**

| Filter | SQL |
|--------|-----|
| `has_gclid=true` | `WHERE gclid IS NOT NULL` |
| `has_gclid=false` | `WHERE gclid IS NULL` |
| `is_potential_quote=true` | `WHERE annot.is_potential_quote = true` |
| `is_potential_quote=false` | `WHERE (annot.is_potential_quote = false OR annot.contact_submission_id IS NULL)` |
| `date_from` | `WHERE created_at >= $value` (DateTimeImmutable from DTO) |
| `date_to` | `WHERE created_at < ($value + 1 day)` (DateTimeImmutable from DTO; inclusive-end-of-day semantics) |
| `conversion_status=none` | `NOT EXISTS (actions with type IN lead_received, quote_issued)` |
| `conversion_status=lead_pending` | `EXISTS (action_type=lead_received AND status IN pending,processing)` |
| `conversion_status=lead_sent` | `EXISTS (action_type=lead_received AND status=completed)` |
| `conversion_status=quote_pending` | `EXISTS (action_type=quote_issued AND status IN pending,processing)` |
| `conversion_status=quote_sent` | `EXISTS (action_type=quote_issued AND status=completed)` |

**Mapper:** Receives `ContactSubmissionModel` with dynamic joined/subquery attributes. Maps to `ContactSubmissionListItem` VO. Notes:

- Cast columns on `ContactSubmissionModel` (e.g., `customer_type`, `reason`, `product`, `created_at`) are auto-cast by Eloquent.
- Joined `annot_*` columns and subquery aliases (`lead_status`, `quote_status`, `helpscout_external_id`) are raw — no casts apply. The mapper must:
  - Parse `annot_quoted_at` via `CarbonImmutable::parse()` when non-null (returns `DateTimeImmutable`)
  - Cast `annot_is_potential_quote` to `?bool` (PostgreSQL returns string/bool — coerce explicitly)
  - Map `lead_status` / `quote_status` strings via `ActionStatus::tryFrom()`
  - Access via `$model->getAttribute('lead_status')` (PHPStan-friendly)

#### 14. `app/Infrastructure/Ingest/ContactSubmission/Repositories/EloquentContactSubmissionRepository.php` (MODIFIED)

Implement `existsById(string $id): bool`:
```php
public function existsById(string $id): bool
{
    return $this->eloquentGateway->exists(ContactSubmissionModel::class, 'id', $id);
}
```

---

### Phase 4: Presentation Layer (4 files)

#### 15. `app/Presentation/Http/Api/DTOs/ListContactSubmissionsRequestDTO.php`

Spatie Data DTO with properties: `per_page` (default 50, max 100), `page`, `has_gclid` (?bool), `is_potential_quote` (?bool), `date_from` (?string), `date_to` (?string), `conversion_status` (?string).

Rules: `date_from`/`date_to` validated as `date_format:Y-m-d`; `date_to` has `after_or_equal:date_from`; `conversion_status` is `in:` ConversionStatus values.

`toQuery()` builds `ContactSubmissionListQueryParams`. **Important:** Converts `date_from` to `CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay()` and `date_to` to `CarbonImmutable::createFromFormat('Y-m-d', $value)->addDay()->startOfDay()` to enable the half-open `< (date_to+1)` comparison.

#### 16. `app/Presentation/Http/Api/DTOs/UpsertContactSubmissionAnnotationRequestDTO.php`

Spatie Data DTO with `Optional`-typed properties for partial-patch semantics:

```php
final class UpsertContactSubmissionAnnotationRequestDTO extends Data
{
    public function __construct(
        public readonly bool|null|Optional $is_potential_quote,
        public readonly string|null|Optional $notes,
        public readonly string|null|Optional $quoted_at,
    ) {}

    public static function rules(ValidationContext $context): array
    {
        return [
            'is_potential_quote' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'quoted_at' => ['nullable', 'date'],
        ];
    }

    public function toCommand(Guid $id): UpsertAnnotationCommand
    {
        return new UpsertAnnotationCommand(
            contactSubmissionId: $id->value,
            isPotentialQuote: $this->is_potential_quote instanceof Optional ? UnsetValue::Instance : $this->is_potential_quote,
            notes: $this->notes instanceof Optional ? UnsetValue::Instance : $this->notes,
            quotedAt: $this->quoted_at instanceof Optional
                ? UnsetValue::Instance
                : ($this->quoted_at !== null ? new DateTimeImmutable($this->quoted_at) : null),
        );
    }
}
```

Spatie's `Optional` instance means "field was absent from the request". `null` means "explicitly cleared". Three states per field, exactly what partial-patch needs.

#### 17. `app/Presentation/Http/Api/Resources/ContactSubmissionListResource.php`

`@mixin ContactSubmissionListItem`. Maps all fields to snake_case JSON:
- Enums as `.value`
- Dates as ATOM format
- `id` as `$item->id->value` (Guid → string)
- `reason->value`, `customer_type?->value`, `lead_status?->value`, `quote_status?->value`

#### 18. `app/Presentation/Http/Api/Controllers/ContactSubmissionDashboardController.php`

`final readonly class` with `BuildsPaginatedResponseTrait`. Two methods:

```php
public function index(ListContactSubmissionsRequestDTO $data): ResourceCollection
{
    return $this->paginatedResponse($this->listUseCase->execute($data->toQuery()), ContactSubmissionListResource::class);
}

/** @throws RecordNotFoundException + DB exceptions */
public function upsertAnnotation(string $id, UpsertContactSubmissionAnnotationRequestDTO $data): JsonResponse
{
    $this->annotationUseCase->execute($data->toCommand(Guid::from($id)));
    return new JsonResponse(null, Response::HTTP_NO_CONTENT);
}
```

Class-level `@throws` lists every exception any method raises (per controller rules).

---

### Phase 5: Wiring (2 files modified)

#### 19. `routes/api.php` — Add to the Consumer API middleware group

```php
Route::get('contact-submissions', [ContactSubmissionDashboardController::class, 'index']);
Route::put('contact-submissions/{id}/annotations', [ContactSubmissionDashboardController::class, 'upsertAnnotation'])
    ->whereUuid('id');
```

#### 20. `app/Providers/ContactSubmissionServiceProvider.php` — Add bindings

Add to `registerRepositories()`:
```php
$this->app->singleton(
    ContactSubmissionDashboardQueryRepositoryInterface::class,
    EloquentContactSubmissionDashboardQueryRepository::class,
);
$this->app->singleton(
    ContactSubmissionAnnotationRepositoryInterface::class,
    EloquentContactSubmissionAnnotationRepository::class,
);
```

Add both to `provides()`.

---

## Verification

### Tests

| Test | Location | What it verifies |
|------|----------|------------------|
| `ListContactSubmissionsUseCaseTest` | `tests/Unit/Application/ContactSubmission/UseCases/` | Use case calls repo and returns its result; logging |
| `UpsertContactSubmissionAnnotationUseCaseTest` | same | Throws RecordNotFoundException when submission absent; calls upsert otherwise |
| `ListContactSubmissionsRequestDTOTest` | `tests/Unit/Presentation/Http/Api/DTOs/` | Filter mapping, date_to → next-day conversion, enum validation |
| `UpsertContactSubmissionAnnotationRequestDTOTest` | same | Optional vs null vs value tri-state mapping to command |
| `EloquentContactSubmissionDashboardQueryRepositoryTest` | `tests/Feature/Infrastructure/Marketing/Repositories/` | Integration: real DB, each filter combination, join correctness, action subquery semantics |
| `EloquentContactSubmissionAnnotationRepositoryTest` | same dir | Upsert insert + update path; explicit-update-columns preserves untouched columns |

### Manual Smoke Test

```bash
# List with filters
curl -H "X-Local-Bypass: $API_BYPASS_SECRET" \
  "http://127.0.0.1:${API_PORT:-8000}/api/contact-submissions?per_page=5&has_gclid=true&conversion_status=none"

# Annotate (partial — only is_potential_quote)
curl -X PUT -H "X-Local-Bypass: $API_BYPASS_SECRET" -H "Content-Type: application/json" \
  -d '{"is_potential_quote": true}' \
  "http://127.0.0.1:${API_PORT:-8000}/api/contact-submissions/{uuid}/annotations"

# 404 path
curl -X PUT ... "http://127.0.0.1:${API_PORT:-8000}/api/contact-submissions/00000000-0000-0000-0000-000000000000/annotations"
```

### Linting

`make lint` — PHPStan, Pint, PHPArkitect, Deptrac, TLint must all pass.

---

## File Summary (~14 files matches issue estimate)

| Layer | New | Modified |
|-------|-----|----------|
| Domain | 3 | 0 |
| Application | 6 | 1 |
| Infrastructure | 3 | 1 |
| Presentation | 4 | 0 |
| Wiring | 0 | 2 |
| Tests | 6 | 0 |
| **Total** | **22** | **4** |
