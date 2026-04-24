# Presentation Layer — Scoped Rules Migration

## Context

`.claude/rules/` was recently introduced for path-scoped rules that only load when Claude opens a matching file, replacing always-loaded CLAUDE.md content. The Eloquent rules have been migrated (repositories, write/view models, migrations, repo contracts). The Presentation layer is next.

Current state: **two** CLAUDE.md files in Presentation:
- `app/Presentation/CLAUDE.md` — exception-handling strategy, directory organization, controller naming. Mixes architecture and per-file conventions.
- `app/Presentation/Http/HelpScout/Resources/CLAUDE.md` — HelpScout-specific resource conventions (ATOM dates, `array_filter()` null omission, field mappings for the alz-admin Zod schema contract). Narrow, feature-local. **Preserved as-is** — its location already scopes it to the right files, and its patterns diverge from the general API resource pattern (no `baseFields()` + `hasInclude()` composition).

The top-level `app/Presentation/CLAUDE.md` mixes two very different kinds of guidance:

1. Architecture-level (decision tree for exception handling, "delivery mechanism" framing, no business logic) — must fire everywhere.
2. File-shape conventions (controller naming, directory layout, request-DTO style, resource composition, middleware shape, command signatures) — only actionable when editing that specific file type.

Category 2 is scattered, undersized (much of it isn't in CLAUDE.md at all — it's implicit in the code), and currently costs tokens on every file Claude opens. Migrating it into scoped rules:
- Makes the conventions explicit instead of tribal.
- Loads them exactly when relevant.
- Opens room to add file-type–specific guidance (e.g. the `RejectsUnknownFieldKeysTrait` usage, the `hasInclude()` resource pattern, the security-log channel) that would bloat an always-loaded CLAUDE.md.

Intended outcome: five path-scoped rule files covering controllers, request DTOs, resources, middleware, and console commands; `app/Presentation/CLAUDE.md` trimmed to the architectural decision tree only.

## FormRequest Migration (in scope)

`app/Presentation/Http/Requests/SetFreeDeliveryRequest.php` is the **only** Laravel `FormRequest` in the codebase, used once by `ProductUpdateController::updateFreeDelivery`. It will be replaced with a Spatie Data pair so the new DTO rule can flatly forbid `FormRequest` with no exception clause.

**New files:**
- `app/Presentation/Http/Api/DTOs/UpdateFreeDeliveryRequestDTO.php` — `final class ... extends Data`, holds `public readonly DataCollection $updates` typed via `#[Min(1), Max(1000), DataCollectionOf(FreeDeliveryUpdateItemDTO::class)]`. `rules()` covers the envelope (`updates.required|array|min:1|max:1000`). No `messages()` override — matches every other Spatie DTO in the codebase (zero currently use it, zero tests assert specific strings).
- `app/Presentation/Http/Api/DTOs/FreeDeliveryUpdateItemDTO.php` — nested item matching the `CostPriceItemDTO` shape: wire-type properties on the DTO (`public readonly int|string $identifier`, `public readonly string $type`), with enum/VO construction inside `toCommand(): SetFreeDeliveryCommand` via `FreeDeliveryType::fromString($this->type)`. `rules()` covers `identifier: required` and `type: required|string|Rule::enum(FreeDeliveryType::class)`.

Shape mirrors the existing `UpdateCostPricesRequestDTO` + `CostPriceItemDTO` pair (`app/Presentation/Http/Api/DTOs/UpdateCostPricesRequestDTO.php:25`, `.../CostPriceItemDTO.php`). Route stays on `POST /products/free-delivery` inside the Consumer API group (`routes/api.php:134`) — authenticated JSON endpoint, not a public form.

**Modified:**
- `ProductUpdateController::updateFreeDelivery` — swap the param type to `UpdateFreeDeliveryRequestDTO $data`, build commands by mapping `$data->updates` through `->toCommand()` (consistent with `updateCostPrices` which uses `array_map(fn(CostPriceItemDTO $item) => $item->toCommand(), iterator_to_array(...))`), drop the `SetFreeDeliveryRequest` import.

**Deleted:**
- `app/Presentation/Http/Requests/SetFreeDeliveryRequest.php` — after the swap, and the now-empty `Http/Requests/` directory.

## Proposed Rule Files

Each file goes in `.claude/rules/` with `paths:` frontmatter. Layout modelled on the existing Eloquent rules (terse, DO/DO NOT/EXCEPTION bullets, canonical class pointers).

### 1. `presentation-controllers.md`

**Paths:**
```yaml
- "app/Presentation/Http/**/Controllers/**/*Controller.php"
```

**Rules to capture (condensed from existing controllers):**
- DO default to `final readonly class` with constructor-promoted `private` use-case dependencies; drop `readonly` when there are no injected dependencies (e.g. invokable health checks). Canonical with-DI: `BrandController`. Canonical no-DI: `QueueHealthController`.
- DO inject concrete use-case / service classes, never generic interfaces — use cases are the contract boundary
- DO NOT add try/catch — the global handler in `bootstrap/app.php` maps domain exceptions to HTTP responses. EXCEPTION: transaction rollback + redirect that the handler can't replicate
- DO list every domain exception the use case can raise in `@throws` on both the class docblock and each method — there's no linter that infers this from use-case `@throws`
- DO convert wire types to value objects at the Presentation/Application boundary — `IntId::from($productId)` for typed route params, `Sku::fromString(...)` / `Money::exclusive(...)` inside a DTO's `toCommand()`, etc. The use case receives domain types, never raw scalars from the wire.
- DO return `new JsonResponse(null, Response::HTTP_NO_CONTENT)` for successful writes with no body
- DO wrap a single domain result in `{Entity}DetailResource`; paginated lists via `$this->paginatedResponse($result, {Entity}Resource::class)` from `BuildsPaginatedResponseTrait`
- DO delegate any work that reaches Domain, Infrastructure, or an external API to an Application use case. EXCEPTION: pure framework-facade operations with no business logic (queue depth, signed URL redirect) — Canonical: `QueueHealthController`, `FeedController`.
- **Splitting:** DO keep one `{Feature}Controller` per table for slim CRUD. DO split into `{Feature}Controller` (reads) + `{Feature}UpdateController` (writes) when writes pull in external-API exception surface (retry/auth/validation) — keeps each controller's `@throws` tractable. Canonical: `BrandController` + `BrandUpdateController`.
- **Invokable:** DO use `__invoke(...)` for single-action entry points (webhook handlers, one-shot form submissions). The parameter may be `Request`, a request DTO, or any container-resolvable type (e.g. `AuthenticatedUser`) — Laravel resolves the signature from the container and route binding. Canonical: `ContactFormController::__invoke(Request)`, `ProfileController::__invoke(AuthenticatedUser)`.

### 2. `presentation-request-dtos.md`

**Paths:**
```yaml
- "app/Presentation/Http/**/DTOs/**/*RequestDTO.php"
```

Scoped to `*RequestDTO` only — non-validating DTOs like `CostPriceItemDTO`, `SkuPriceUpdateDTO`, and `WebhookEnvelopeDTO` are plain Spatie carriers and don't need the rejects-unknown-fields / validates-includes guidance.

**Rules:**
- DO extend `Spatie\LaravelData\Data` and declare the class `final` (not `final readonly` — matches every existing request DTO; Spatie 4.22 supports readonly but the codebase convention is per-property `public readonly`)
- DO use attribute-form validation on scalar properties: `#[IntegerType, Min(1), Max(1000)]`
- DO use `public static function rules()` for array-keyed payloads (`'fields' => [...]`, `'fields.title' => [...]`)
- DO use `RejectsUnknownFieldKeysTrait` + implement `allowedFieldKeys()` whenever accepting a closed-set `fields` map — prevents unexpected keys silently reaching the use case
- DO use `ValidatesIncludesTrait` + implement `allowedIncludes()` for any endpoint accepting an `include=` query param; DO declare `public readonly ?string $include = null`
- DO mark properties `public readonly` with defaults where the endpoint accepts omission
- **Item DTOs:** DO expose a `toCommand(): {Domain}Command` method on DTOs that map 1:1 to a Domain command. Hold wire types on the DTO (string, int, float, nullable scalars); construct value objects / enums / Money inside `toCommand()`. Canonical: `CostPriceItemDTO::toCommand()`.

### 3. `presentation-api-resources.md`

**Paths:**
```yaml
- "app/Presentation/Http/Api/Resources/**/*Resource.php"
```

Scoped to `Http/Api/Resources/` only. HelpScout resources (`Http/HelpScout/Resources/`) follow a different pattern (see the preserved local CLAUDE.md there) — matching both with one rule would mis-apply `baseFields()`/`hasInclude()` guidance to HelpScout files.

**Rules:**
- DO extend `Illuminate\Http\Resources\Json\JsonResource`, declare the class `final`, and add `@mixin {DomainValueObject}` to the class docblock so `$this->resource` autocompletes in `toArray()`
- DO override with `#[Override] public function toArray(Request $request): array`
- **List / Detail pair (top-level entities only):** when an entity has BOTH a list and a detail endpoint, DO expose `public static function baseFields({VO} $vo): array` on the list resource (`{Entity}Resource`) and compose the detail resource (`{Entity}DetailResource`) as `baseFields(...) + conditionalIncludes(...) + ['meta' => ...]`. EXCEPTION: nested / child resources used via `::collection(...)` from a parent resource — these are single-tier, no `baseFields()`, no detail variant (canonical: `ProductVariationResource`).
- DO guard optional fields with `$result->hasInclude({EntityInclude}::Foo)` — never inline null-checks; the include enum is the contract
- DO use `static fn(...): array` closures when mapping collections of value objects
- DO wrap `{DetailResource}` around a Use Case Result when it carries both the domain object AND the requested includes list (`GetProductResult`); DO wrap it around the raw value object only when there are no conditional includes

### 4. `presentation-http-middleware.md`

**Paths:**
```yaml
- "app/Presentation/Http/**/Middleware/**/*Middleware.php"
```

**Rules:**
- DO declare `final class` and define `public function handle(Request $request, Closure $next): Response`; use `final readonly class` when the middleware holds only constructor-injected dependencies (rare; canonical: `DetectRefreshMiddleware`)
- DO stash cross-middleware data on `$request->attributes` (e.g. `authenticated_user`, `forceRefresh`); downstream middleware and controllers read via `$request->attributes->get(...)`
- DO emit security events (auth failures, signature mismatches, unapproved access) via `Log::channel('security')` with a structured context array carrying `event`, `path`, `ip`, and where known `user_id`/`email` — these feed separate log retention
- DO return `new ApiErrorResponseDTO(...)->toJsonResponse()` for rejections, NOT raw `new JsonResponse(['error' => ...])` — guarantees the shared `{"error": {type, message, errors?}}` envelope
- DO use `hash_equals` for every HMAC / signature / bypass-secret comparison — never `===` or `strcmp`. **Why**: timing-attack hardening; prior guidance is scattered.
- DO declare middleware ordering constraints in the class docblock when they exist (`MUST run AFTER ValidateSupabaseJwtMiddleware`) — ordering is otherwise silent until a 500 in prod

### 5. `presentation-console-commands.md`

**Paths:**
```yaml
- "app/Presentation/Console/Commands/**/*Command.php"
```

**Rules:**
- DO declare the class `final extends Command` with a multi-line `$signature` and a one-line `$description`
- DO return `self::SUCCESS` / `self::FAILURE` from `handle()` — never `0` / `1` literals
- DO delegate to a use case; commands are thin parsers + presenters, not business logic
- DO catch boundary `ValueError` when parsing an enum option (`FreeDeliveryType::fromString($opt)`) and render it as `$this->error(...)` + valid-values list; DO NOT let enum parse errors bubble as an exception trace
- DO output results via `$this->info()` / `->warn()` / `->table()` — never raw `echo`
- DO expose a `--dry-run` option on any command that dispatches jobs or mutates external systems
- DO include a "⚠️ PRODUCTION ONLY" block and `railway ssh ...` example in the class docblock when the command writes to live third-party systems. **Why**: local runs against production credentials leave the audit trail in the wrong database — prior incident with `inventory:update-skus`.

## CLAUDE.md Trimming

`app/Presentation/CLAUDE.md` currently mixes architecture and per-file conventions. Post-migration it keeps only:

- Purpose ("delivery mechanism") — first paragraph preserved verbatim
- Exception Handling Decision Tree (the controller-vs-command split)
- Anti-Patterns (no catch-to-log, no business logic in Presentation, **no Laravel `FormRequest` — all HTTP input uses Spatie LaravelData**). The FormRequest ban lives in CLAUDE.md rather than `presentation-request-dtos.md` so it fires when Claude is authoring *any* new file in Presentation (the scoped rule's `*RequestDTO.php` glob wouldn't match a new `*Request.php` file).
- Golden Rule: "Presentation speaks Laravel to framework, business concepts to users." — preserved, reframes decisions when neither architecture nor scoped rules give a clear answer.
- One-line pointer: "See `.claude/rules/` for per-file conventions."

Remove: "Directory Organization" table (it's discoverable from the tree) and "Naming" bullet (now in `presentation-controllers.md`).

## Files Touched

**New (rules):**
- `.claude/rules/presentation-controllers.md`
- `.claude/rules/presentation-request-dtos.md`
- `.claude/rules/presentation-api-resources.md`
- `.claude/rules/presentation-http-middleware.md`
- `.claude/rules/presentation-console-commands.md`

**New (FormRequest migration):**
- `app/Presentation/Http/Api/DTOs/UpdateFreeDeliveryRequestDTO.php`
- `app/Presentation/Http/Api/DTOs/FreeDeliveryUpdateItemDTO.php`

**Modified:**
- `app/Presentation/CLAUDE.md` — trim to architecture-only content.
- `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — swap `SetFreeDeliveryRequest` → `UpdateFreeDeliveryRequestDTO` in `updateFreeDelivery()`, update command builder to iterate the typed `DataCollection`.

**Deleted:**
- `app/Presentation/Http/Requests/SetFreeDeliveryRequest.php`
- `app/Presentation/Http/Requests/` directory (empty after deletion).

## Rollout

Single commit: five rule files + CLAUDE.md trim + FormRequest migration. The `paths:` loading mechanism was already validated by the Eloquent migration, so no pilot is needed.

## Verification

1. Pre-migration sanity check: use the `Grep` tool for `FormRequest` under `app/Presentation` — confirms `SetFreeDeliveryRequest.php` is the only hit matching `extends FormRequest` before deletion, and zero hits after. (Global CLAUDE.md forbids `grep`/`rg`/`find` in Bash.)
2. Confirm `make lint` and `make test` pass after the FormRequest migration — the controller swap touches validation behaviour for one endpoint.
3. Open a controller under `Http/Api/Controllers/` in a fresh session — `presentation-controllers.md` should appear in the loaded rules; opening `app/Domain/...` should load none of the five new rules.
4. Read-through each bullet and apply the trimming test from `.claude/rules/CLAUDE.md` — remove the bullet and ask "would Claude still write compliant code from the code context alone?" If yes, drop it before committing.
