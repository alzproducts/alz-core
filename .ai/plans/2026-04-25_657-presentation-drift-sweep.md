# Plan: Presentation Drift Sweep — Domain VO `toArray()` Leak + 3 Inline-Response Cleanups

**Issue:** #657
**Related issues:** #655 (deferred HelpScout typed-context work), #656 (broader Domain VO audit)

---

## Problem

Four places in the Presentation layer bypass established response-building patterns:

1. **Domain leak** — `AbstractCustomFieldValue::toArray()` and `DateTimeCustomFieldValue::toArray()` bake wire-format decisions (snake_case JSON keys, ATOM date formatting, stringified enums) into the Domain layer. Called from 3 controllers + 3 Detail Resources. Domain CLAUDE.md says "zero external dependencies, framework-agnostic."
2. **`ProductUpdateController::updatePrices()`** — private `buildPriceUpdateResponse()` + `mapFailures()` helpers build a raw `array<string, mixed>`. Its sibling `updateCostPrices()` already uses `BulkUpdateResponseDTO` (Responsable). Inconsistent within the same controller.
3. **`HelpScout/ConversationsController` (4 actions) + `ProfileController`** — every action wraps its return in `new JsonResponse(['data' => Resource::collection(...)])` instead of returning the collection directly. Laravel's ResourceCollection auto-wraps with `data`.
4. **`ContactFormController::__invoke()`** — returns `new JsonResponse(['id' => $result->submissionId], Response::HTTP_OK)` inline. No Responsable DTO, no Resource. One-off synthesised envelope that should follow the pattern established by `BulkUpdateResponseDTO` / `AsyncRefreshAcceptedResponseDTO`.

---

## Design Decisions (from grilling session 2026-04-25)

| Decision | Choice |
|---|---|
| PR scope | One PR for all 4 cases |
| `CustomFieldValueResource` shape | Identical to today's `$vo->toArray()` JSON shape (no API contract change) |
| Date formatting in Resource | `match(true) { $field instanceof DateTimeCustomFieldValue => $field->value->format(ATOM) }` — Resource owns it |
| Nested use in Detail Resources | `CustomFieldValueResource::collection($fields)->resolve($request)` |
| Domain `toArray()` deletion | Both `AbstractCustomFieldValue::toArray()` and `DateTimeCustomFieldValue::toArray()` removed |
| VO test rewrites | Assertions migrated from `->toArray()` output to typed accessors (`name()`, `type()`, `rawValue()`, `label()`, direct property access) |
| `PriceUpdateResponseDTO` | New `final readonly class` implementing `Responsable`, `::fromResult(PriceUpdateResult $result)` factory. Mirrors `BulkUpdateResponseDTO`. Shape: `total`, `succeeded`, `skipped[]`, `permanent_failures[]`, `temporary_failures[]`. Status 200. |
| HelpScout response shape | Return `ConversationResource::collection($conversations)` / `AgentProfileResource` directly (no `['data' => ...]` wrap). **Do NOT touch `resolveAgentId()`** — deferred to #655. |
| `ContactSubmissionAcceptedResponseDTO` | New `final readonly class` implementing `Responsable`. Shape: `['id' => $this->submissionId]`. Status 200. Named with `Accepted` suffix consistent with `AsyncRefreshAcceptedResponseDTO`. |
| Commit order | Case 1 first (architectural), then 2, 3, 4, rule last |
| New rule file | `.claude/rules/domain-value-objects.md` scoped to `app/Domain/**/*ValueObject*.php` + `app/Domain/**/ValueObjects/*.php` |
| Broader Domain sweep | Deferred to #656 (audit + fix issue) |

---

## Implementation Steps

### Commit 1 — Case 1: `CustomFieldValueResource` + Domain cleanup

**New file:** `app/Presentation/Http/Api/Resources/CustomFieldValueResource.php`

```php
final class CustomFieldValueResource extends JsonResource
{
    // @mixin AbstractCustomFieldValue

    #[Override]
    public function toArray(Request $request): array
    {
        /** @var AbstractCustomFieldValue $field */
        $field = $this->resource;

        return [
            'name' => $field->name(),
            'type' => $field->type()->value,
            'label' => $field->label(),
            'value' => match (true) {
                $field instanceof DateTimeCustomFieldValue
                    => $field->value->format(DateTimeInterface::ATOM),
                default => $field->rawValue(),
            },
            'allowed_values' => $field->definition->base->allowedValues,
            'sort_order' => $field->definition->base->sortOrder,
        ];
    }
}
```

**Remove from Domain:**
- `AbstractCustomFieldValue::toArray()` (lines ~67–77) — delete method entirely
- `DateTimeCustomFieldValue::toArray()` (lines ~76–82 override) — delete override entirely

**Update 3 controllers** (`BrandController`, `CategoryController`, `ProductController`) — `customFields()` action:
```php
// Before:
return new JsonResponse([
    'data' => array_map(static fn(AbstractCustomFieldValue $field): array => $field->toArray(), $fields),
]);

// After:
return CustomFieldValueResource::collection($fields);
```

**Update 3 Detail Resources** (`BrandDetailResource`, `CategoryDetailResource`, `ProductDetailResource`) — custom_fields conditional include:
```php
// Before:
$data['custom_fields'] = array_map(static fn(AbstractCustomFieldValue $field): array => $field->toArray(), $brand->customFields);

// After:
$data['custom_fields'] = CustomFieldValueResource::collection($brand->customFields)->resolve($request);
```

**Rewrite VO tests:**

Enumerate affected test files via Grep on `tests/` for `->toArray()` calls on `AbstractCustomFieldValue` and its 5 subclasses (`StringCustomFieldValue`, `DateTimeCustomFieldValue`, `ToggleCustomFieldValue`, `ValueListCustomFieldValue`, `ProductListCustomFieldValue`, `NullCustomFieldValue`).

**Rewrite rules — apply per assertion, do NOT delete tests:**

For every test that asserts on `$vo->toArray()` output, transform as follows:

| Old assertion pattern | New assertion pattern |
|---|---|
| `expect($vo->toArray()['name'])->toBe('foo')` | `expect($vo->name())->toBe('foo')` |
| `expect($vo->toArray()['type'])->toBe('text')` | `expect($vo->type())->toBe(CustomFieldType::Text)` (assert on the enum, not the string) |
| `expect($vo->toArray()['label'])->toBe('Foo')` | `expect($vo->label())->toBe('Foo')` |
| `expect($vo->toArray()['value'])->toBe('bar')` | `expect($vo->rawValue())->toBe('bar')` (or `$vo->value` for direct property — both StringCustomFieldValue.value / ToggleCustomFieldValue.value / DateTimeCustomFieldValue.value / ValueListCustomFieldValue.values / ProductListCustomFieldValue.productIds) |
| `expect($vo->toArray()['allowed_values'])->toBe([...])` | `expect($vo->definition->base->allowedValues)->toBe([...])` |
| `expect($vo->toArray()['sort_order'])->toBe(5)` | `expect($vo->definition->base->sortOrder)->toBe(5)` |
| Whole-array equality: `expect($vo->toArray())->toBe([...])` | Replace with key-by-key assertions on typed accessors above. |

**Do NOT:**
- Delete entire test methods, even if they become 1-line typed-accessor checks. Preserve the original `it()` description so the test inventory stays comparable in CI.
- Combine multiple `it()` cases into one. Keep granularity.
- Convert PHP-array `value` assertions on `DateTimeCustomFieldValue` to ATOM string assertions in the VO test — that assertion **moves** to the new `CustomFieldValueResourceTest`. Keep only the typed assertion `expect($vo->value)->toBeInstanceOf(DateTimeImmutable::class)` and a timezone/value check on the VO test.

**New file:** `tests/Unit/Presentation/Http/Api/Resources/CustomFieldValueResourceTest.php`

Cover:
- One test per subtype (`String`, `Toggle`, `DateTime`, `ValueList`, `ProductList`, `Null`) asserting the full Resource output for a representative VO instance
- The DateTime ATOM-formatting assertion (the one previously in `DateTimeCustomFieldValueTest::toArray()`) lives here
- The `null` value when wrapping `NullCustomFieldValue`
- The `allowed_values` and `sort_order` keys come through from `definition->base`

Use `(new CustomFieldValueResource($vo))->toArray(Request::create('/'))` directly for assertions — avoid full HTTP roundtrips.

---

### Commit 2 — Case 2: `PriceUpdateResponseDTO`

**New file:** `app/Presentation/Http/Api/Responses/PriceUpdateResponseDTO.php`

```php
final readonly class PriceUpdateResponseDTO implements Responsable
{
    public function __construct(
        private int $total,
        private int $succeeded,
        private array $skipped,       // list<array{sku: string, reason: string}>
        private array $permanentFailures,  // list<array{sku: string|null, error: string}>
        private array $temporaryFailures,  // list<array{sku: string|null, error: string}>
    ) {}

    public static function fromResult(PriceUpdateResult $result): self { ... }
    private static function mapFailures(array $failures): array { ... }

    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse([
            'total' => $this->total,
            'succeeded' => $this->succeeded,
            'skipped' => $this->skipped,
            'permanent_failures' => $this->permanentFailures,
            'temporary_failures' => $this->temporaryFailures,
        ]);
    }
}
```

**Update `ProductUpdateController::updatePrices()`:**
- Replace `return new JsonResponse($this->buildPriceUpdateResponse($result))` with `return PriceUpdateResponseDTO::fromResult($result)`
- Delete `buildPriceUpdateResponse()` and `mapFailures()` private methods
- Update return type from `JsonResponse` to `PriceUpdateResponseDTO`

---

### Commit 3 — Case 3: HelpScout response shape

**`ConversationsController`** — update 4 action return types and bodies:
- `assigned()`, `todos()`, `negativeReviews()`, `escalations()` — return `ConversationResource::collection($conversations)` directly
- Return type: `ResourceCollection`
- Drop the `new JsonResponse(['data' => ...])` wrapping

**`ProfileController`** — needs a new `AgentProfileResource` (verified: does not exist in `app/Presentation/Http/HelpScout/Resources/`).

Create `app/Presentation/Http/HelpScout/Resources/AgentProfileResource.php` with the existing fields from `ProfileController::__invoke()` lines 38–45:

```php
final class AgentProfileResource extends JsonResource
{
    // @mixin Agent  (or whichever VO is the source — check getAgentProfile() return type)

    #[Override]
    public function toArray(Request $request): array
    {
        /** @var Agent $agent */
        $agent = $this->resource;

        return [
            'id' => $agent->id,
            'email' => $agent->email,
            'firstName' => $agent->firstName,
            'lastName' => $agent->lastName,
            'role' => $agent->role,
        ];
    }
}
```

Then `ProfileController::__invoke()` becomes:
```php
return new AgentProfileResource($this->service->getAgentProfile($user->email));
```

Note the camelCase keys (`firstName`, `lastName`) — preserved from the current inline body to avoid contract change. Confirm by reading the existing `ProfileController.php:38–45` before writing the Resource.

**Note:** Do NOT touch `resolveAgentId()` — deferred to #655.

---

### Commit 4 — Case 4: `ContactSubmissionAcceptedResponseDTO`

**New file:** `app/Presentation/Http/Api/Responses/ContactSubmissionAcceptedResponseDTO.php`

```php
final readonly class ContactSubmissionAcceptedResponseDTO implements Responsable
{
    public function __construct(private string $submissionId) {}

    public static function from(string $submissionId): self
    {
        return new self($submissionId);
    }

    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse(['id' => $this->submissionId], Response::HTTP_OK);
    }
}
```

**Update `ContactFormController::__invoke()`:**
- Replace `return new JsonResponse(['id' => $result->submissionId], Response::HTTP_OK)` with `return ContactSubmissionAcceptedResponseDTO::from($result->submissionId)`
- Update return type

---

### Commit 5 — New rule file

**New file:** `.claude/rules/domain-value-objects.md`

```yaml
---
paths:
  - "app/Domain/**/*ValueObject*.php"
  - "app/Domain/**/ValueObjects/*.php"
---
```

Rule content (draft):

- DO NOT add `toArray()`, `toJson()`, or any serialisation method that commits to a wire format: snake_case keys, stringified enums (`->value` in the return), formatted dates (`->format(...)`), or encoding booleans as non-native types.
- DO return native PHP types from Domain methods: `DateTimeImmutable`, enum instances, `int`, `string`, `bool`, `null`, arrays of native types.
- DO add typed accessors (`name()`, `label()`, `type()`, `rawValue()`) for individual properties — consumers decide how to format them.
- EXCEPTION: a structural `toArray()` that returns native types only (no wire conventions) and is used exclusively for internal Domain/Application purposes is acceptable. Document the use case in a comment.
- Canonical violation example: `AbstractCustomFieldValue::toArray()` (removed in this PR). Canonical correct example: `CustomFieldValueResource::toArray(Request $request)` in Presentation.

---

## File Checklist

### New files
- [ ] `app/Presentation/Http/Api/Resources/CustomFieldValueResource.php`
- [ ] `app/Presentation/Http/Api/Responses/PriceUpdateResponseDTO.php`
- [ ] `app/Presentation/Http/Api/Responses/ContactSubmissionAcceptedResponseDTO.php`
- [ ] `app/Presentation/Http/HelpScout/Resources/AgentProfileResource.php`
- [ ] `.claude/rules/domain-value-objects.md`
- [ ] `tests/Unit/Presentation/Http/Api/Resources/CustomFieldValueResourceTest.php`

### Modified files
- [ ] `app/Domain/Catalog/CustomFields/ValueObjects/AbstractCustomFieldValue.php` — remove `toArray()`
- [ ] `app/Domain/Catalog/CustomFields/ValueObjects/DateTimeCustomFieldValue.php` — remove `toArray()` override
- [ ] `app/Presentation/Http/Api/Controllers/BrandController.php` — `customFields()` action
- [ ] `app/Presentation/Http/Api/Controllers/CategoryController.php` — `customFields()` action
- [ ] `app/Presentation/Http/Api/Controllers/ProductController.php` — `customFields()` action
- [ ] `app/Presentation/Http/Api/Resources/BrandDetailResource.php` — custom_fields include
- [ ] `app/Presentation/Http/Api/Resources/CategoryDetailResource.php` — custom_fields include
- [ ] `app/Presentation/Http/Api/Resources/ProductDetailResource.php` — custom_fields include
- [ ] `app/Presentation/Http/Api/Controllers/ProductUpdateController.php` — `updatePrices()`, remove private helpers
- [ ] `app/Presentation/Http/Controllers/HelpScout/ConversationsController.php` — 4 action return types
- [ ] `app/Presentation/Http/Controllers/HelpScout/ProfileController.php` — return type (if inline body needs a Resource)
- [ ] `app/Presentation/Http/Controllers/ContactForm/ContactFormController.php` — `__invoke()` return
- [ ] Domain VO test files (enumerate via Grep during implementation)

---

## Open Questions (resolve during implementation)

1. **VO test file count** — Use Grep on `tests/` during implementation to enumerate affected test files. Per-assertion rewrite rules are specified above; only the count is open.
2. **`ConversationResource::collection()` return type** — Confirm it's `ResourceCollection` for the updated `ConversationsController` return-type declarations.
3. **`getAgentProfile()` return type** — When creating `AgentProfileResource`, confirm the source VO type (likely `Agent` or similar) by reading `CachingHelpScoutService::getAgentProfile()` signature. Use that type in the `@mixin` annotation.

## Resolved during planning

- ~~Does `AgentProfileResource` exist?~~ Verified: it does not. Plan includes the create step (see Commit 3 — Case 3 above).

---

## Out of Scope

- `resolveAgentId()` / `forceRefresh` middleware migration → **#655**
- Broader Domain VO `toArray()` audit → **#656**
- Any functional behaviour change to existing API endpoints
