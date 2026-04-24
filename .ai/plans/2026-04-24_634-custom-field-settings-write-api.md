# Custom Field Settings — Write API

## Context

Issue #611 delivered Phase 1: the `catalog.custom_field_general_settings` and `catalog.custom_field_product_settings` tables, the `ConfiguredFieldDefinition` composition wrapper, and a fully-typed read path. The tables can now hold data, but there are no HTTP endpoints to write it. This PR adds the consumer-facing API so the frontend can list definitions, view their local settings, and edit them.

## Decisions (locked 2026-04-24)

### Endpoints (split by resource, one controller per table)

```
GET    /catalog/custom-field-definitions
GET    /catalog/custom-field-definitions/{id}
PATCH  /catalog/custom-field-definitions/{id}/general-settings
PATCH  /catalog/custom-field-definitions/{id}/product-settings   → 422 if item_type ≠ 'product'
```

### PATCH semantics — JSON Merge Patch
- Absent field → unchanged
- Explicit `null` → clear (nullable columns only)
- Implemented via Spatie LaravelData `Optional` properties

### Write response — full enriched definition
Both PATCH endpoints return the complete `ConfiguredFieldDefinition` shape (same as GET) so the frontend can replace its local cache entry in one round-trip.

### Upsert on first write
First PATCH creates the settings row; subsequent PATCHes update it. Uses `AbstractEloquentRepository::save()` → `EloquentGateway::upsertOne()` with `uniqueBy: ['custom_field_definition_id']`. DB default handles `admin_only = false` when absent from first-create body.

### Request parsing — Spatie LaravelData (no FormRequest)
DTOs with `#[Rule(...)]` attributes provide the Laravel Validator pipeline. No separate FormRequest layer.

### Authorization
Same consumer API auth (Supabase JWT + MFA). Manager-level lock-down is a separate follow-up issue (see decision notes in session).

### No audit stamps
No `created_by`/`updated_by` in this PR.

---

## Architecture

### Controllers (3, one per resource — `app/Presentation/Http/Catalog/`)

| Controller | Methods | Resource |
|---|---|---|
| `CustomFieldDefinitionsController` | `index()`, `show()` | `shopwired.custom_field_definitions` (enriched read) |
| `CustomFieldGeneralSettingsController` | `__invoke()` PATCH | `catalog.custom_field_general_settings` |
| `CustomFieldProductSettingsController` | `__invoke()` PATCH | `catalog.custom_field_product_settings` |

### Use Cases (`app/Application/Catalog/CustomFields/`)

- `ListConfiguredFieldDefinitionsUseCase`
- `GetConfiguredFieldDefinitionUseCase`
- `SaveCustomFieldGeneralSettingsUseCase`
- `SaveCustomFieldProductSettingsUseCase`

Use case flow for writes:
1. Resolve definition via `CustomFieldRepositoryInterface` → verify exists (404 guard)
2. For product settings: assert `definition->base->isProductField()` → throw `ProductSettingsNotApplicableException` if false
3. Load existing settings VO via new repo interface `findByDefinitionId()` → may be `null`
4. Merge incoming Spatie Data DTO onto existing VO (absent = unchanged)
5. Call `$repo->save($mergedVO)` — upserts
6. Re-load and return full enriched `ConfiguredFieldDefinition`

### Domain additions

- `ProductSettingsNotApplicableException` — new domain exception; thrown when a write to `product_settings` is attempted on a non-product definition. Presentation catches → 422 with `code: product_settings_not_applicable`.

### Repositories (2 new, `app/Application/Contracts/` + `app/Infrastructure/Catalog/CustomFields/Repositories/`)

Both extend `AbstractEloquentRepository`. Interface in `Application/Contracts/` extending `RepositoryWriteInterface<T>`:

```php
// CustomFieldGeneralSettingsRepositoryInterface
public function findByDefinitionId(UUID $definitionId): ?CustomFieldGeneralSettings;

// CustomFieldProductSettingsRepositoryInterface
public function findByDefinitionId(UUID $definitionId): ?ProductFieldSettings;
```

`getUpsertKeys()` returns `['custom_field_definition_id']` on both. Models (`CustomFieldGeneralSettingsModel`, `CustomFieldProductSettingsModel`) already exist from #611; add `attributesFromDomain()` static methods if not already present.

---

## Validation Details

### General settings DTO

| Field | PHP type | Rules |
|---|---|---|
| `tooltip` | `string\|null\|Optional` | `nullable`, `string`, `max:500` |
| `select_type` | `string\|null\|Optional` | `nullable`, `Rule::enum(CustomFieldValueSelectType::class)` |
| `suggest_common_data` | `bool\|null\|Optional` | `nullable`, `boolean` |
| `admin_only` | `bool\|Optional` | `boolean` (NOT null — DB default handles first-create) |
| `field_validation_rule` | `int\|null\|Optional` | `nullable`, `Rule::enum(CustomFieldValidationRule::class)` |

### Product settings DTO

| Field | PHP type | Rules |
|---|---|---|
| `stock_item_update_mode` | `string\|null\|Optional` | `nullable`, `Rule::enum(LinnworksStockItemUpdateMode::class)` |

Note: column in migration is `stock_item_update_mode`; plan #611 shows `update_linnworks_stock_item` — trust the migration.

---

## Response Shape

```json
{
  "id": "uuid",
  "name": "string",
  "item_type": "product | order | ...",
  "general": {
    "tooltip": "string | null",
    "select_type": "category | brand | product | null",
    "suggest_common_data": "bool | null",
    "admin_only": "bool",
    "field_validation_rule": "int | null"
  },
  "product": {
    "stock_item_update_mode": "single | all_variants | null"
  }
}
```

- `general` — always present; uses `CustomFieldGeneralSettings::defaults()` when no row exists.
- `product` — `null` if `item_type ≠ 'product'` OR if no product settings row exists yet.

---

## Files to Create

| File | Notes |
|---|---|
| `app/Presentation/Http/Catalog/CustomFieldDefinitionsController.php` | `index()` + `show()` |
| `app/Presentation/Http/Catalog/CustomFieldGeneralSettingsController.php` | Invokable PATCH |
| `app/Presentation/Http/Catalog/CustomFieldProductSettingsController.php` | Invokable PATCH |
| `app/Application/Catalog/CustomFields/ListConfiguredFieldDefinitionsUseCase.php` | |
| `app/Application/Catalog/CustomFields/GetConfiguredFieldDefinitionUseCase.php` | |
| `app/Application/Catalog/CustomFields/SaveCustomFieldGeneralSettingsUseCase.php` | |
| `app/Application/Catalog/CustomFields/SaveCustomFieldProductSettingsUseCase.php` | |
| `app/Application/Contracts/CustomFieldGeneralSettingsRepositoryInterface.php` | |
| `app/Application/Contracts/CustomFieldProductSettingsRepositoryInterface.php` | |
| `app/Infrastructure/Catalog/CustomFields/Repositories/EloquentCustomFieldGeneralSettingsRepository.php` | |
| `app/Infrastructure/Catalog/CustomFields/Repositories/EloquentCustomFieldProductSettingsRepository.php` | |
| Route entries under consumer API route group | |

## Files to Modify

| File | Change |
|---|---|
| Routes file | Add 4 new routes |
| Service provider (catalog/shopwired) | Bind new repo interfaces |
| `app/Domain/Exceptions/` | Add `ProductSettingsNotApplicableException` |
| Existing settings models | Add `attributesFromDomain()` if missing (confirm from #611 work) |

---

## Verification

1. `GET /catalog/custom-field-definitions` returns all definitions enriched with settings.
2. `GET /catalog/custom-field-definitions/{id}` returns single enriched definition, 404 if missing.
3. `PATCH .../general-settings {}` on a definition with no settings row creates the row with DB defaults.
4. `PATCH .../general-settings {"tooltip": "hello"}` on an existing row updates tooltip only; other fields unchanged.
5. `PATCH .../product-settings` on a non-product definition returns `422` with `{"code": "product_settings_not_applicable"}`.
6. Write response matches GET response shape for the same definition.
7. `make lint && make test` pass.
