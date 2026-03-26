# Plan: Product Custom Fields — Read Endpoint + Write Endpoint

## Context

The frontend needs custom field data for products — both reading (with enough metadata to build form fields) and writing (validated updates pushed to ShopWired). The existing `?include=custom_fields` on the show endpoint strips metadata (label, allowedValues, sortOrder). The write path needs strict validation before calling the ShopWired API.

**Two deliverables:**
1. **Read**: Enrich `toArray()` + new `GET /api/products/{productId}/custom-fields` endpoint
2. **Write**: Extract strict builder from factory + Application-layer validator + new `POST /api/products/{productId}/custom-fields` endpoint

---

## Part A: Read Endpoint (enriched custom fields)

### A1. Enrich `AbstractCustomFieldValue::toArray()`

**File:** `app/Domain/Catalog/CustomFields/ValueObjects/AbstractCustomFieldValue.php`

Add `label`, `allowed_values`, `sort_order` from the embedded definition:

```php
public function toArray(): array
{
    return [
        'name' => $this->name(),
        'type' => $this->type()->value,
        'label' => $this->definition->label,
        'value' => $this->rawValue(),
        'allowed_values' => $this->definition->allowedValues,
        'sort_order' => $this->definition->sortOrder,
    ];
}
```

**Side effect:** Existing `?include=custom_fields` on the show endpoint automatically gets enriched (additive, non-breaking).

### A2. Override `toArray()` in `DateTimeCustomFieldValue`

**File:** `app/Domain/Catalog/CustomFields/ValueObjects/DateTimeCustomFieldValue.php`

Format `DateTimeImmutable` as ISO 8601 ATOM (matches convention from `ProductResource:73`):

```php
public function toArray(): array
{
    return [
        'name' => $this->name(),
        'type' => $this->type()->value,
        'label' => $this->definition->label,
        'value' => $this->value->format(DateTimeInterface::ATOM),
        'allowed_values' => $this->definition->allowedValues,
        'sort_order' => $this->definition->sortOrder,
    ];
}
```

### A3. New use case — `GetProductCustomFieldsUseCase`

**File:** `app/Application/Catalog/UseCases/GetProductCustomFieldsUseCase.php` (new)

- Calls existing `ProductRepositoryInterface::findProductForApi($productId, ['custom_fields'])`
- Filters by requested field names (if any)
- Returns `list<AbstractCustomFieldValue>`

Reuses: `ProductRepositoryInterface::findProductForApi()` — no new repository methods.

### A4. New request DTO — `GetProductCustomFieldsRequestDTO`

**File:** `app/Presentation/Http/Api/DTOs/GetProductCustomFieldsRequestDTO.php` (new)

Optional `fields` query param: `?array`, validated as array of strings (max 40 chars each). `fieldNames(): list<string>` accessor.

### A5. Add `customFields()` GET action to `ProductController`

**File:** `app/Presentation/Http/Api/Controllers/ProductController.php`

New action returning `JsonResponse` with `{data: [...]}` envelope. Inline `array_map` with `->toArray()`.

### A6. Register GET route

**File:** `routes/api.php` (~line 148)

```php
Route::get('products/{productId}/custom-fields', [ProductController::class, 'customFields'])
    ->whereNumber('productId');
```

---

## Part B: Write Endpoint (validated custom field updates)

### B1. Extract `CustomFieldValueFactory` (Infrastructure — strict builder)

**File:** `app/Infrastructure/Shopwired/Factories/CustomFieldValueFactory.php` (new)

Extracted from `ProductCustomFieldFactory`. This is the strict version:

- Constructor receives `CustomFieldDefinitionRegistry`
- `fromRawFields(array<string, mixed> $rawFields): list<AbstractCustomFieldValue>`
- **Throws `CustomFieldNotFoundException`** for unknown field names (unlike current factory which logs and skips)
- **Throws `InvalidCustomFieldValueException`** for type mismatches (same as current)
- Contains all extracted `createTypedValue()`, `createStringValue()`, `createToggleValue()`, `createDateTimeValue()`, `createValueListValue()`, `createProductListValue()` static methods

### B2. Define `CustomFieldValueFactoryInterface` (Application contract)

**File:** `app/Application/Contracts/Shopwired/CustomFieldValueFactoryInterface.php` (new)

```php
interface CustomFieldValueFactoryInterface
{
    /**
     * @param array<string, mixed> $rawFields
     * @return list<AbstractCustomFieldValue>
     *
     * @throws CustomFieldNotFoundException
     * @throws InvalidCustomFieldValueException
     */
    public function fromRawFields(array $rawFields): array;
}
```

`CustomFieldValueFactory` implements this interface.

### B3. Refactor `ProductCustomFieldFactory` to delegate

**File:** `app/Infrastructure/Shopwired/Factories/ProductCustomFieldFactory.php` (modify)

- Lazy-loads registry (unchanged)
- Creates `CustomFieldValueFactory` with the registry
- `fromRawFields()` delegates to the new factory, catching `CustomFieldNotFoundException` → logs warning and skips (preserves current graceful degradation)
- `InvalidCustomFieldValueException` passes through (unchanged behavior)

### B4. Create `CustomFieldSubmissionValidator` (Application layer)

**File:** `app/Application/Catalog/Validators/CustomFieldSubmissionValidator.php` (new)

Implements `ValidatorInterface`. Constructed inline with data (matching existing validator pattern):

```php
final readonly class CustomFieldSubmissionValidator implements ValidatorInterface
{
    /**
     * @param array<string, mixed> $rawFields Submitted field name => value pairs
     */
    public function __construct(
        private CustomFieldValueFactoryInterface $factory,
        private array $rawFields,
    ) {}

    public function validate(): CustomFieldSubmissionResult
    {
        try {
            $this->factory->fromRawFields($this->rawFields);
            return CustomFieldSubmissionResult::passed();
        } catch (CustomFieldNotFoundException $e) {
            return CustomFieldSubmissionResult::unknownField($e->fieldName, $e->itemType);
        } catch (InvalidCustomFieldValueException $e) {
            return CustomFieldSubmissionResult::invalidValue($e->fieldName, $e->expectedType, $e->actualType);
        }
    }
}
```

### B5. Create `CustomFieldSubmissionResult` (Application layer)

**File:** `app/Application/Catalog/Validators/CustomFieldSubmissionResult.php` (new)

Implements `DescribableValidationResultInterface`, uses `ThrowsOnValidationFailureTrait`. Named constructors for each failure mode:

- `::passed()` — validation succeeded
- `::unknownField(string $fieldName, CustomFieldItemType $itemType)` — field not in registry
- `::invalidValue(string $fieldName, CustomFieldType $expectedType, string $actualType)` — type mismatch

Provides `reason()` (human-readable) and `context()` (structured for Sentry/logging).

### B6. Create `UpdateProductCustomFieldsUseCase` (Application)

**File:** `app/Application/Catalog/UseCases/UpdateProductCustomFieldsUseCase.php` (new)

```
1. Validate submission:
   $result = (new CustomFieldSubmissionValidator($this->valueFactory, $rawFields))->validate();
   if ($result->failed()) → throw UserInputValidationFailedException

2. Call existing update API:
   $this->productUpdateClient->updateCustomFields($productId, $rawFields);
```

Injected dependencies:
- `CustomFieldValueFactoryInterface` (for validator construction)
- `ProductUpdateClientInterface` (existing — fetch-merge-PUT to ShopWired)
- `LoggerInterface`

### B7. New request DTO — `UpdateProductCustomFieldsRequestDTO`

**File:** `app/Presentation/Http/Api/DTOs/UpdateProductCustomFieldsRequestDTO.php` (new)

Validates request body shape:
- `custom_fields`: required, array
- `custom_fields.*`: each key is a string (max 40 chars), values are mixed

### B8. Add `updateCustomFields()` POST action to `ProductController`

**File:** `app/Presentation/Http/Api/Controllers/ProductController.php`

New action. Calls the use case, returns `204 No Content` on success.

### B9. Register POST route

**File:** `routes/api.php`

```php
Route::post('products/{productId}/custom-fields', [ProductController::class, 'updateCustomFields'])
    ->whereNumber('productId');
```

Same middleware group as GET route (Supabase JWT + approval + throttle + Sentry).

---

## Files Summary

| File | Action | Layer |
|------|--------|-------|
| `app/Domain/Catalog/CustomFields/ValueObjects/AbstractCustomFieldValue.php` | Enrich `toArray()` | Domain |
| `app/Domain/Catalog/CustomFields/ValueObjects/DateTimeCustomFieldValue.php` | Override `toArray()` for ATOM | Domain |
| `app/Application/Catalog/UseCases/GetProductCustomFieldsUseCase.php` | New (read) | Application |
| `app/Application/Contracts/Shopwired/CustomFieldValueFactoryInterface.php` | New (builder contract) | Application |
| `app/Application/Catalog/Validators/CustomFieldSubmissionValidator.php` | New (validator) | Application |
| `app/Application/Catalog/Validators/CustomFieldSubmissionResult.php` | New (result) | Application |
| `app/Application/Catalog/UseCases/UpdateProductCustomFieldsUseCase.php` | New (write) | Application |
| `app/Infrastructure/Shopwired/Factories/CustomFieldValueFactory.php` | New (strict builder) | Infrastructure |
| `app/Infrastructure/Shopwired/Factories/ProductCustomFieldFactory.php` | Refactor to delegate | Infrastructure |
| `app/Presentation/Http/Api/DTOs/GetProductCustomFieldsRequestDTO.php` | New (GET) | Presentation |
| `app/Presentation/Http/Api/DTOs/UpdateProductCustomFieldsRequestDTO.php` | New (POST) | Presentation |
| `app/Presentation/Http/Api/Controllers/ProductController.php` | Add 2 actions + DI | Presentation |
| `routes/api.php` | Add GET + POST routes | Presentation |

## Reused Existing Classes

| Class | Path | Usage |
|-------|------|-------|
| `ProductRepositoryInterface::findProductForApi()` | `app/Application/Contracts/Shopwired/` | Read endpoint — load product with custom fields |
| `ProductUpdateClientInterface::updateCustomFields()` | `app/Application/Contracts/Shopwired/` | Write endpoint — fetch-merge-PUT to ShopWired |
| `CustomFieldDefinitionRegistry` | `app/Infrastructure/Shopwired/CustomFields/` | Registry passed to the new builder |
| `CustomFieldNotFoundException` | `app/Domain/Catalog/CustomFields/Exceptions/` | Unknown field name |
| `InvalidCustomFieldValueException` | `app/Domain/Catalog/CustomFields/Exceptions/` | Type mismatch |
| `UserInputValidationFailedException` | `app/Domain/Exceptions/` | Validation failure HTTP response |
| `ValidatorInterface` | `app/Domain/Shared/Validation/Contracts/` | Validator pattern |
| `ThrowsOnValidationFailureTrait` | `app/Domain/Shared/Validation/Concerns/` | Result orFail() |

## Tests

**Unit — Domain (toArray enrichment):**
- `tests/Unit/Domain/Catalog/CustomFields/ValueObjects/AbstractCustomFieldValueTest.php` (new)
- Verify all 6 keys, null handling, DateTimeCustomFieldValue ATOM format

**Unit — Application (validator + use case):**
- `tests/Unit/Application/Catalog/Validators/CustomFieldSubmissionValidatorTest.php` (new)
- Passed, unknownField, invalidValue scenarios
- `tests/Unit/Application/Catalog/UseCases/GetProductCustomFieldsUseCaseTest.php` (new)
- All fields, filtered fields, empty result, 404 passthrough
- `tests/Unit/Application/Catalog/UseCases/UpdateProductCustomFieldsUseCaseTest.php` (new)
- Validation pass → update called, validation fail → UserInputValidationFailedException, API error passthrough

**Unit — Infrastructure (builder + factory refactor):**
- `tests/Unit/Infrastructure/Shopwired/Factories/CustomFieldValueFactoryTest.php` (new)
- Throws CustomFieldNotFoundException for unknown fields, throws InvalidCustomFieldValueException for type mismatches, returns typed values for valid input
- Update existing `ProductCustomFieldFactory` tests to verify delegation + log-and-skip behavior

**Feature — Controller:**
- `tests/Feature/Presentation/Http/Api/Controllers/ProductControllerTest.php` (extend)
- GET: enriched response, field filtering, unknown fields → empty, 404, 401
- POST: valid update → 204, unknown field → 422, type mismatch → 422, 404, 401

## Verification

1. `make lint` — PHPStan, Pint, PHPArkitect, Deptrac, TLint
2. `make test` — full suite including new tests
3. Manual GET: `curl -H "X-Local-Bypass: $API_BYPASS_SECRET" "http://127.0.0.1:8000/api/products/{id}/custom-fields"`
4. Manual GET with filter: `curl ... "?fields[]=discontinued&fields[]=stock_status"`
5. Manual POST: `curl -X POST -H "Content-Type: application/json" -H "X-Local-Bypass: $API_BYPASS_SECRET" -d '{"custom_fields":{"discontinued":"yes"}}' "http://127.0.0.1:8000/api/products/{id}/custom-fields"`
6. Existing `?include=custom_fields` on show endpoint — verify enriched output (backward-compatible)
