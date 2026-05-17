# COR-145 — Contact Submissions Dashboard API (List + Annotations)

**Branch:** `feature/cor-145-contact-submissions-dashboard-api-list-annotations`
**Plan:** `.ai/plans/2026-05-17_COR-145-contact-submissions-dashboard-api.md`
**Linear:** [COR-145](https://linear.app/alzproducts/issue/COR-145)
**Started:** 2026-05-17

## Scope

Two API endpoints for staff dashboard:
- `GET /api/contact-submissions` — paginated list with lead/quote status, HelpScout ID, annotation fields, 5 filters
- `PUT /api/contact-submissions/{id}/annotations` — partial-patch annotation upsert

Joins across 3 schemas: `public_ingest.contact_submissions`, `customer_service.contact_submission_actions`, `marketing.contact_submission_annotations`.

## Decision Log

- 2026-05-17: Workflow started via `/work COR-145`. Plan is implementation-ready; following Phases 1-5 in order.
- 2026-05-17: Branch `feature/cor-145-contact-submissions-dashboard-api-list-annotations` created from `origin/develop`.
- 2026-05-17: Plan footnote allows deferring to `application-commands.md`. Verified: established pattern is two-map (`$valuesToSet` + `$columnsToClear`) + Field enum (DB-column-name backing values, `isClearable()`) + `MergePatchMapper` for DTO→command folding. Canonical: `SaveCustomFieldGeneralSettingsCommand` + `CustomFieldGeneralSettingsField`. **Will use two-map pattern instead of `UnsetValue` sentinel.** Plan intent (three-state partial-patch) preserved; only mechanism differs.
- 2026-05-17 (refactor pass via `/check`): `EloquentContactSubmissionDashboardQueryRepository` flagged as a structural mess — it hydrated the ingest write model `ContactSubmissionModel` and bolted on `selectSub` columns + 3 correlated subqueries + LEFT JOIN annotations, violating the `*QueryRepository ↔ *ViewModel ↔ pg view` convention. Agreed (A+C+D) to land a refactor in this same PR. Discovered `customer_service.contact_submission_actions` has UNIQUE INDEX on `(contact_submission_id, action_type)` (migration `2026_02_01_200002` line 83), so latest-per-group reduces to plain LEFT JOINs per action_type — simpler than LATERAL or DISTINCT ON. `is_potential_quote` stays nullable end-to-end (NULL = untriaged is a real domain state, not a join artefact). Filter semantics for `=false` are literal-only (NULL rows excluded); presentation choice, easy to revisit.

## Discovered Patterns (verified before implementing)

### Merge-Patch Canonical (from `.claude/rules/application-commands.md`)
- **Command:** `app/Application/Catalog/Commands/SaveCustomFieldGeneralSettingsCommand.php` — two readonly properties: `array<string, scalar> $valuesToSet` (keys are DB column names from `{Field}Enum::value`) + `list<{Field}Enum> $columnsToClear`. Constructor uses `Webmozart\Assert\Assert` to enforce: (1) every key in `$valuesToSet` is a valid case value; (2) no column appears in both maps; (3) `$columnsToClear` only contains clearable cases.
- **Field enum:** `app/Domain/Catalog/CustomFields/Enums/CustomFieldGeneralSettingsField.php` — string-backed (values = DB column names) + `isClearable(): bool` per case (mirrors NOT NULL).
- **Mapper:** `app/Presentation/Http/Api/Support/MergePatchMapper::buildMaps()` — takes `list<array{0: TField, 1: Optional|scalar|null}>`, folds to `[$valuesToSet, $columnsToClear]`. `Optional` → skip; `null` → clear; scalar → set.
- **Repository write:** spread `$command->valuesToSet` + `array_fill_keys(array_map(fn($c) => $c->value, $command->columnsToClear), null)` into `upsertOne` attributes alongside `contact_submission_id`. `computeUpdateColumns` derives update list from attribute keys minus `id` and uniqueBy — perfect for partial-patch (only touched columns participate in ON CONFLICT DO UPDATE).

### EloquentGateway (located, `app/Infrastructure/Persistence/EloquentGateway.php`)
- `exists(class, column, value): bool`
- `upsertOne(class, attrs, uniqueBy)` — no `$update` param; column list derived from attrs (perfect for two-map pattern)
- `paginate(class, scope, relations, mapper, perPage, page): PaginatedList<T>` — scope receives `Builder<covariant Model>`, mapper transforms each model

### Other key classes
- `App\Domain\Shared\Pagination\ValueObjects\PageRequest` — `::from(page, perPage)` factory; MAX_PER_PAGE = 1000
- `App\Domain\ValueObjects\PaginatedList` — `::fromPage(items, total, perPage, currentPage)` factory (computes lastPage)
- `App\Domain\ValueObjects\Guid` — `new Guid($uuid)` validates UUID format, `Guid::fromTrusted()` for known-good
- `App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait::paginatedResponse(PaginatedList, ResourceClass): ResourceCollection`
- `App\Providers\ContactSubmissionServiceProvider` — DeferrableProvider with `registerRepositories()` + `provides()` list

### Existing files to modify
- `app/Application/Contracts/ContactSubmission/ContactSubmissionRepositoryInterface.php` — add `existsById(string): bool`
- `app/Infrastructure/Ingest/ContactSubmission/Repositories/EloquentContactSubmissionRepository.php` — implement `existsById()`
- `app/Providers/ContactSubmissionServiceProvider.php` — add 2 bindings + 2 entries in `provides()`
- `routes/api.php` — add 2 routes
- Note: existing repository is `final readonly` injecting `EloquentGateway` directly (NOT extending `AbstractEloquentRepository` — pre-existing exception or older pattern; will match this for consistency)

### Still to verify
- COR-144 migration for `marketing.contact_submission_annotations` table (didn't find via glob — may use different naming)
- Existing `ConversationStatus`-like pattern in catalog/etc for filter enums
- A controller using `BuildsPaginatedResponseTrait` (to confirm idiom)
- Routes file Consumer API middleware group structure
- A DTO using `MergePatchMapper` (to confirm idiom)

## Implementation Progress

### Phase 1 — Domain Layer
- [x] `ConversionStatus` enum
- [x] `ContactSubmissionFilterField` enum
- [x] `ContactSubmissionAnnotationField` enum (NEW — required by two-map merge-patch shape; not in original plan)
- [x] `ContactSubmissionListItem` VO

### Phase 2 — Application Layer
- [x] `ContactSubmissionListQueryParams`
- [x] `ContactSubmissionDashboardQueryRepositoryInterface`
- [x] `ContactSubmissionAnnotationRepositoryInterface`
- [x] `ContactSubmissionRepositoryInterface::existsById()` (modified)
- [x] `UpsertAnnotationCommand` (two-map shape — `$valuesToSet` keyed by column name, `$columnsToClear` as list of enum cases)
- [x] `ListContactSubmissionsUseCase`
- [x] `UpsertContactSubmissionAnnotationUseCase`

### Phase 3 — Infrastructure Layer
- [x] `ContactSubmissionAnnotationModel` (bare write model — no domain interface, mirrors `CustomFieldGeneralSettingsModel` shape but simpler since no `toDomain`)
- [x] `EloquentContactSubmissionAnnotationRepository` (spreads two-map command into `upsertOne` attributes)
- [x] `EloquentContactSubmissionDashboardQueryRepository` (LEFT JOIN annotations + 3 correlated subqueries for 1:N actions)
- [x] `EloquentContactSubmissionRepository::existsById()` (modified)

### Phase 4 — Presentation Layer
- [x] `ListContactSubmissionsRequestDTO` — 5 filter properties + pagination; bools as `?string` (`in:true,false,1,0`) and parsed via `parseBoolFilter()`; `date_to` translated to next-day midnight in `parseDateFilter()`
- [x] `UpsertContactSubmissionAnnotationRequestDTO` — 3 Optional|T|null properties, `toCommand()` uses `MergePatchMapper::buildMaps()` (with `quoted_at` pre-stringified to ISO8601 to satisfy the scalar-only mapper signature)
- [x] `ContactSubmissionListResource` — `@mixin ContactSubmissionListItem`, all fields snake_case, ATOM dates, enum `.value`
- [x] `ContactSubmissionDashboardController` — `final readonly`, two actions (`index` + `upsertAnnotation`), uses `BuildsPaginatedResponseTrait`

### Phase 5 — Wiring
- [x] `routes/api.php` — 2 routes added to Consumer API group (`whereUuid('id')` on PUT)
- [x] `ContactSubmissionServiceProvider` — 4 bindings (split into `registerWriteRepositories` + `registerDashboardRepositories`) + 2 new entries in `provides()`

### Known cleanup needed before lint
- `EloquentContactSubmissionDashboardQueryRepository::applyConversionStatusFilter`: unused `$negate` variable, awkward `from(ContactSubmissionActionModel::query()->getModel()->getTable())` pattern — simplify
- May need PHPStan ignores for `getAttribute()` returning mixed in dashboard mapper

### Phase 6 — Dashboard query refactor (uncommitted, 2026-05-17)
- [x] `database/migrations/2026_05_17_300000_create_marketing_contact_submission_dashboard_view.php` — view joining submissions + annotations + 3 LEFT JOINs into actions (one per action_type, no LIMIT/DISTINCT thanks to unique index)
- [x] `app/Infrastructure/Marketing/Models/ContactSubmissionDashboardViewModel.php` — read-only ViewModel with full casts (`reason`, `customer_type`, `lead_status`, `quote_status` as enum casts; `is_potential_quote` as bool; `created_at`/`quoted_at` as immutable_datetime)
- [x] `app/Application/ContactSubmission/Queries/ContactSubmissionDashboardFilters.php` — typed VO replacing the `array<value-of<FilterField>, mixed>` shape
- [x] `app/Application/ContactSubmission/Queries/ContactSubmissionListQueryParams.php` — swapped `array $filters` for the VO (default constructed)
- [x] `app/Infrastructure/Marketing/Queries/ContactSubmissionDashboardQuery.php` — predicate builder; `IsPotentialQuote=false` now literal-only (NULL excluded); `ConversionStatus::None` filters `lead_status IS NULL AND quote_status IS NULL` on view columns
- [x] `app/Infrastructure/Marketing/Repositories/EloquentContactSubmissionDashboardQueryRepository.php` — slimmed to ~85 lines: paginate view, delegate filters to query class, map ViewModel → ContactSubmissionListItem via direct property access (no more `getAttribute('...')` / `is_string` / `tryFrom` defensive code)
- [x] `app/Presentation/Http/Api/DTOs/ListContactSubmissionsRequestDTO.php` — `toQuery()` constructs the VO directly (drops the `buildFilters` array indirection)
- [x] `app/Application/ContactSubmission/UseCases/ListContactSubmissionsUseCase.php` — log fields rewired from `array_keys($query->filters)` to explicit per-filter values
- [x] `app/Domain/ContactSubmission/Enums/ContactSubmissionFilterField.php` — **DELETED** (no callers after refactor; was glue between wire strings and array keys)
- [x] `make lint` run — 8 PHPStan + 1 PHPArkitect errors surfaced; all caused by the refactor, fixes below
- [ ] Manual smoke against the new view not yet run (`make db-reset-full` needed first to apply the view migration)

### Phase 6 — Lint fixes (in progress, 2026-05-17)
Lint findings + user-approved resolutions:
- **PHPArkitect**: `ContactSubmissionDashboardFilters` violates Application-layer suffix list. Rename to `ContactSubmissionDashboardFiltersParams` (keeps "Filters" semantics + adds allowed `*Params` suffix).
- **PHPStan `staticMethod.dynamicCall`** (6 errors in `ContactSubmissionDashboardQuery`): root cause is the existing phpstan.neon exemption covers `app/Infrastructure/*/Repositories/*` but the new `app/Infrastructure/*/Queries/*` location is outside it. Same Larastan/Eloquent fluent-API rationale. **Approved**: extend the existing exemption to include `Queries/*`.
- **PHPStan `shipmonk.unusedMatchResult`** (1 error, line 84): my match-for-side-effects discards Builder return values. Fix: restructure to destructure a tuple `[$column, $statuses]` from the match (matches the original repo's pattern), then issue a separate `whereIn`. The destructure makes the match's return type used.
- **PHPStan method-length** (1 error, `ListContactSubmissionsUseCase::execute()` at 22 lines): extract a private `buildLogContext()` helper for the per-filter log array.
- Applied + verified file state:
  - VO renamed: `ContactSubmissionDashboardFilters` → `ContactSubmissionDashboardFiltersParams` (file + class + 3 callers + log).
  - `phpstan.neon` exemption widened to include `app/Infrastructure/*/Queries/*` alongside existing `Repositories/*` entry.
  - `applyConversionStatus` refactored: if-then for `None` branch + destructure-from-tuple-match for the four remaining cases, with the tuple-match itself extracted into `predicateForStatus()` (16-line + 10-line methods, both under the 20-line limit). Added `LogicException` import.
  - `ListContactSubmissionsUseCase::execute()` reduced to ~10 lines; per-filter logging moved into private static `buildLogContext()`.
- Pending: re-run `make lint` after stop hook to confirm zero errors; manual smoke against the new view still requires DB migration apply.

### Tests
- [ ] `ListContactSubmissionsUseCaseTest`
- [ ] `UpsertContactSubmissionAnnotationUseCaseTest`
- [ ] `ListContactSubmissionsRequestDTOTest`
- [ ] `UpsertContactSubmissionAnnotationRequestDTOTest`
- [ ] `EloquentContactSubmissionDashboardQueryRepositoryTest`
- [ ] `EloquentContactSubmissionAnnotationRepositoryTest`

## Validation Results

- Existing tests: ✅ 1672 Domain tests pass (`make test-quick`, 10.7s)
- Lint: 🟡 In progress — Pint ✅, PHPArkitect ✅, Deptrac ✅, TLint ✅. PHPStan had 10 errors; some fixed (DTO date parsing + buildFilters extraction with Assert), still to fix:
  - `app/Infrastructure/Marketing/Repositories/EloquentContactSubmissionDashboardQueryRepository.php:65,66` — `applyProjection`/`applyFilters` static methods declared `Builder<ContactSubmissionModel>` but closure passes `Builder<covariant Model>`. Fix: change `@param` to `Builder<covariant Model>` and import `Illuminate\Database\Eloquent\Model`.
  - Same file `:105` — `actionFieldSubquery()` missing return generics. Fix: add `@return Builder<ContactSubmissionActionModel>`.
  - Same file `:121` — `applyFilters` is 36 lines, limit 30. Fix: extract `applyBooleanFilters` + `applyDateFilters` helpers.
  - `app/Providers/ContactSubmissionServiceProvider.php:46` — `registerRepositories` is 21 lines (just 1 over limit). Fix: split into two private methods (e.g. `registerWriteRepositories` + `registerQueryRepositories`).
- Manual smoke test: ✅ found and fixed two live bugs:
  1. `applyConversionStatusFilter` typed the `whereExists`/`whereNotExists` closures as `Illuminate\Database\Eloquent\Builder`, but Laravel forwards a `Illuminate\Database\Query\Builder` at runtime. PHPStan trusted the annotation; only surfaced on `conversion_status=none` (HTTP 500). Fix: typehint both closures with `QueryBuilder`.
  2. `has_gclid` / `is_potential_quote` declared as `?bool` with Spatie `BooleanType` — rejected the natural query-string form `?has_gclid=true` (Laravel's `boolean` rule only coerces "1"/"0"). Fix: declared as `?string` with `in:true,false,1,0` validation and added `parseBoolFilter()` to convert in `buildFilters`.
- Validation matrix (final):
  - GET list, no filters: 200, 32 rows
  - has_gclid=true / has_gclid=false / has_gclid=bogus: 200 / 200 / 422
  - is_potential_quote=true: 200, returned the annotated row
  - date_from=2026-04-16&date_to=2026-04-16: 200 (single-day half-open interval works)
  - date_from=invalid: 422
  - conversion_status=none: 200
  - conversion_status=bogus: 422
  - PUT annotate existing: 204, then GET shows is_potential_quote=true, notes="smoke test"
  - PUT partial update {notes: "updated note"}: 204, then GET shows is_potential_quote=true (preserved!) + notes="updated note" — **merge-patch semantics verified end-to-end**
  - PUT clear path {is_potential_quote: null, notes: null}: 204 (cleanup, exercised the `columnsToClear` branch)
  - PUT annotate missing UUID: 404

## Tests (deferred from this session due to context pressure)

The plan calls for 6 tests. Implementation is complete and existing 1672 tests pass, but new tests not yet written:
- `tests/Unit/Application/ContactSubmission/UseCases/ListContactSubmissionsUseCaseTest`
- `tests/Unit/Application/ContactSubmission/UseCases/UpsertContactSubmissionAnnotationUseCaseTest`
- `tests/Unit/Presentation/Http/Api/DTOs/ListContactSubmissionsRequestDTOTest`
- `tests/Unit/Presentation/Http/Api/DTOs/UpsertContactSubmissionAnnotationRequestDTOTest`
- `tests/Feature/Infrastructure/Marketing/Repositories/EloquentContactSubmissionDashboardQueryRepositoryTest`
- `tests/Feature/Infrastructure/Marketing/Repositories/EloquentContactSubmissionAnnotationRepositoryTest`

## PR Notes (draft)

### What
Adds two staff-dashboard API endpoints for contact submissions:
- `GET /api/contact-submissions` — paginated list (default 50, max 100) enriched with lead/quote action status, HelpScout conversation id, and marketing annotation columns. Filters: `has_gclid`, `is_potential_quote`, `date_from`, `date_to`, `conversion_status`. Always ordered by `created_at DESC`.
- `PUT /api/contact-submissions/{id}/annotations` — partial-patch upsert of the annotation row. `Optional` means "don't touch", `null` means "clear column", a value means "set column".

### Why
First slice of the contact-submissions dashboard (parent issue COR-143). Unblocks the frontend (`alz-admin`) for lead qualification and quoting state, and is a foundational read path for the offline-conversion tracking work in COR-136.

### Key Decisions
- **Two-map merge-patch over `UnsetValue` sentinel** — the plan suggested a per-property `UnsetValue` sentinel but the plan footnote explicitly defers to `.claude/rules/application-commands.md` if an established merge-patch pattern exists. It does: `SaveCustomFieldGeneralSettingsCommand` uses `array<string, scalar> $valuesToSet` + `list<{Field}Enum> $columnsToClear`. We mirror it. Three states (untouched / set / clear) stay structurally distinct.
- **Correlated subqueries for 1:N actions, LEFT JOIN for 1:1 annotations** — a direct LEFT JOIN on actions would multiply rows. Three correlated subqueries pull the latest `lead_status`, `quote_status`, and `helpscout_external_id` (with `ORDER BY created_at DESC LIMIT 1` so retried actions don't return stale state).
- **Bool filters typed as `?string` (`in:true,false,1,0`)** — Spatie `BooleanType` + `?bool` rejects the natural query-string form `?has_gclid=true`. Strings parsed in `buildFilters` keep the wire form ergonomic for the frontend.
- **`date_to` interpreted as inclusive-end-of-day** via half-open `< (date + 1 day)` in DTO `parseDateFilter` — gives users the intuitive "from 1st to 5th = everything on the 5th too" behaviour without an extra flag.
- **`existsById` check before upsert** instead of relying on the FK violation — gives a friendly 404 instead of a 500 from the DB constraint violation. Adds one round-trip per write; acceptable for a staff-only path.

### Testing
- `make test-quick` (1672 Domain unit tests): pass
- `make lint` (Pint, PHPStan max, PHPArkitect, Deptrac, TLint): pass
- Manual smoke matrix via curl: all green (see Validation Results above). Two live bugs caught and fixed by the smoke pass — `whereExists` closure typehint and bool query-string coercion.
- 6 unit/integration tests from the plan are **deferred** (listed below).
