# Custom Field Settings — Composition Wrapper Rollout

## Context

ShopWired's custom field definitions (`shopwired.custom_field_definitions`) are a synced external contract: identical for all fields of a given type. We now need **local configuration** to augment these definitions with presentation/behavior rules that ShopWired doesn't know about:

- **General settings** (any field): tooltip, select_type override, suggest common data, admin-only visibility, validation rule.
- **Product-specific settings**: "update all variant SKUs" behaviour for Linnworks stock item synchronisation.

The existing `CustomFieldDefinition` is `final readonly` and is the write-path contract for the ShopWired sync. Mutating it to hold local settings would fight both its immutability and its "identical for all fields" shape. Instead we compose: a new `ConfiguredFieldDefinition` VO wraps the ShopWired definition plus our local settings and becomes the **read-path** object everywhere a definition is consumed. The sync/write path keeps operating on the plain `CustomFieldDefinition`.

Per user decisions:
- Tables live in the **`catalog` schema** (local config, co-located with other catalog read models).
- All read paths are updated to `ConfiguredFieldDefinition` — including the `AbstractCustomFieldValue` hierarchy. Single read-path object, simpler mental model.
- Scope = schema + read wiring. No admin CRUD in this PR.
- `field_validation_rule` enum: `Url`, `AlphaNumeric`, `Integer` (int-backed).

## Architecture Decisions

- `CustomFieldDefinition` stays untouched (write/sync contract).
- `ConfiguredFieldDefinition` lives in Domain alongside `CustomFieldDefinition` (embedded in `AbstractCustomFieldValue`, so must be framework-independent).
- Settings VOs are also Domain VOs with `final readonly` + `Webmozart\Assert` invariants.
- `CustomFieldGeneralSettings::defaults()` named constructor supplies sane defaults when no row exists for a definition — keeps the wrapper always-present (no null-general-settings branches downstream).
- `ProductFieldSettings` stays nullable on `ConfiguredFieldDefinition` (only applicable when `itemType === Product`). Invariant asserted in the wrapper's constructor.
- Repository interface still implements `RepositoryWriteInterface<CustomFieldDefinition>` (write path unchanged) but read methods (`findByName`, `findByItemType`, `findAll`) return `ConfiguredFieldDefinition`.
- Eloquent models for settings live in `App\Infrastructure\Catalog\CustomFields\Models\` (mirrors schema choice).
- `CustomFieldDefinitionModel` gains `hasOne` relations, eager-loaded in every repository read. A new method `toConfiguredDomain()` on the model reads eager-loaded relations and builds the wrapper (following `Domain/Catalog/CLAUDE.md`: delegate construction to the source model).

## Files to Create

### Migrations

- `database/migrations/2026_04_22_000001_create_catalog_custom_field_general_settings_table.php`
- `database/migrations/2026_04_22_000002_create_catalog_custom_field_product_settings_table.php`

Both tables:
- `id` uuid PK (`gen_random_uuid()`)
- `custom_field_definition_id` uuid, **unique**, FK → `shopwired.custom_field_definitions.id` with `cascadeOnDelete()` (unique enforces one-to-one for `hasOne`)
- `timestampsTz()`

`catalog.custom_field_general_settings` columns:
- `tooltip` text nullable
- `select_type` string(20) nullable (enum value)
- `suggest_common_data` boolean nullable
- `admin_only` boolean NOT NULL default false
- `field_validation_rule` smallint nullable (int-backed enum)

`catalog.custom_field_product_settings` columns:
- `update_linnworks_stock_item` string(20) nullable (enum value)

### Domain — Enums (`app/Domain/Catalog/CustomFields/Enums/`)

- `CustomFieldValueSelectType.php` — string-backed: `Category='category'`, `Brand='brand'`, `Product='product'`. Names the *source* of values for a select-style field. Deliberately longer than the existing `CustomFieldType` / `CustomFieldItemType` in the same namespace to avoid reader collision.
- `CustomFieldValidationRule.php` — int-backed: `Url=1`, `AlphaNumeric=2`, `Integer=3`
- `LinnworksStockItemUpdateMode.php` — string-backed: `Single='single'` (update only the master Linnworks stock item for that product), `AllVariants='all_variants'` (also loop and update every variation in Linnworks).

### Domain — Value Objects (`app/Domain/Catalog/CustomFields/ValueObjects/`)

- `CustomFieldGeneralSettings.php` — `final readonly`, all fields from the table plus a `public static function defaults(): self` that returns admin_only=false and nulls elsewhere.
- `ProductFieldSettings.php` — `final readonly`, single field `?LinnworksStockItemUpdateMode $updateLinnworksStockItem`.
- `ConfiguredFieldDefinition.php` — `final readonly` with three public properties. Inner property is named `$base` (not `$definition`) to avoid `->definition->definition->` visual stutter inside `AbstractCustomFieldValue`:
  - `CustomFieldDefinition $base`
  - `CustomFieldGeneralSettings $generalSettings`
  - `?ProductFieldSettings $productSettings`

  Invariant: `Assert::true($productSettings === null || $base->isProductField(), 'ProductFieldSettings requires Product item type')`.

  **No delegation accessors.** Callers reach the underlying ShopWired fields via one extra hop: `$configured->base->name`, `$configured->base->type`, `$configured->base->isProductField()`. This keeps the wrapper a pure pass-through structurally and avoids maintaining a parallel API that duplicates `CustomFieldDefinition`'s surface.

### Infrastructure — Eloquent Models (`app/Infrastructure/Catalog/CustomFields/Models/`)

Located in the Catalog module because the tables live in the `catalog` schema and represent local catalog configuration (not synced ShopWired data). `CustomFieldDefinitionModel` keeps its `Shopwired/Models` location; the cross-module `hasOne` from Shopwired → Catalog is fine under Deptrac's single Infrastructure layer.

- `CustomFieldGeneralSettingsModel.php` — implements `EloquentDomainMappableInterface<CustomFieldGeneralSettings>`, manual `toDomain()` because of two enum casts (`select_type` → `CustomFieldValueSelectType`, `field_validation_rule` → `CustomFieldValidationRule`). Provides `static fromDomainAttributes(object $entity): array` excluding the FK (per existing pattern in `CustomFieldDefinitionModel`).
- `CustomFieldProductSettingsModel.php` — same pattern, one enum cast.

Both:
- `protected $table = 'catalog.custom_field_*'`
- `use HasUuids`, `protected $guarded = []`
- `casts()` method for enum + immutable_datetime timestamps
- `public function customFieldDefinition(): BelongsTo`

### Tests (`tests/Unit/Domain/Catalog/CustomFields/`)

- `ValueObjects/ConfiguredFieldDefinitionTest.php` — covers three-property construction, the product-settings invariant (non-null `ProductFieldSettings` requires `base->isProductField()` true — asserts the `InvalidArgumentException` from Webmozart's `Assert::true` when the invariant is violated).
- `ValueObjects/CustomFieldGeneralSettingsTest.php` — covers `defaults()`.
- `ValueObjects/ProductFieldSettingsTest.php` — construction with/without enum.
- `Enums/CustomFieldValueSelectTypeTest.php`, `Enums/CustomFieldValidationRuleTest.php`, `Enums/LinnworksStockItemUpdateModeTest.php` — `tryFrom` coverage (including `LinnworksStockItemUpdateMode::Single` and `AllVariants`).

## Files to Modify

### Domain value hierarchy (read-path ripple)

- `app/Domain/Catalog/CustomFields/ValueObjects/AbstractCustomFieldValue.php` — change constructor param type from `CustomFieldDefinition` to `ConfiguredFieldDefinition` (property stays named `$definition`). Every `$this->definition->X` property read in this class gains a `->base` hop: `$this->definition->base->name`, `$this->definition->base->label`, `$this->definition->base->type`, `$this->definition->base->allowedValues`, `$this->definition->base->sortOrder` (affects `name()`, `label()`, `type()` accessor bodies at lines 41–60 and `toArray()` lines 70–75). `toArray()` keeps its **current key set identical** (`name, type, label, value, allowed_values, sort_order`) — this PR does not change API payload shape. Any admin_only / tooltip / select_type surfacing is a future concern once the admin-role model and frontend consumer exist.
- `app/Domain/Catalog/CustomFields/ValueObjects/NullCustomFieldValue.php` — no code change, just the parent's signature propagates.
- `app/Domain/Catalog/CustomFields/ValueObjects/StringCustomFieldValue.php`, `ToggleCustomFieldValue.php`, `DateTimeCustomFieldValue.php`, `ValueListCustomFieldValue.php`, `ProductListCustomFieldValue.php` — all five explicitly type `CustomFieldDefinition $definition` in their own constructors and call `parent::__construct($definition)`. Swap the parameter type to `ConfiguredFieldDefinition` in each, and update the type-guard assertions + error-message interpolations to reach through `->base`: `$definition->base->type->isStringType()`, `$definition->base->type->isDateType()`, `Assert::same($definition->base->type, CustomFieldType::ValueList, ...)`, `$definition->base->type->value` in messages, `$definition->base->name` / `$definition->base->type` in `DateTimeCustomFieldValue::fromTimestamp()`'s `InvalidCustomFieldValueException` arguments. `DateTimeCustomFieldValue::fromTimestamp()` signature also takes `ConfiguredFieldDefinition`. `rawValue()`, `isEmpty()`, `count()`, `contains()` are unchanged.

### Application / Infrastructure wiring

- `app/Application/Contracts/Shopwired/CustomFieldRepositoryInterface.php` — change return types of `findByName`, `findByItemType`, `findAll` to `ConfiguredFieldDefinition`. Propagate `@throws InvalidApiResponseException` from `toConfiguredDomain()` → `toDomain()` on all three methods (enum-parse failures from general and product settings models surface here). Copy the same `@throws` list onto `CustomFieldFactory::fromRawFields()`, `CustomFieldValueFactory::fromRawFields()`, and the three consuming use cases (`GetProductCustomFieldsUseCase`, `GetCategoryCustomFieldsUseCase`, `GetBrandCustomFieldsUseCase`) — ShipMonk's `missingType.checkedException` and `tooWideThrowType` rules will flag drift.
- `app/Infrastructure/Shopwired/Repositories/EloquentCustomFieldRepository.php` — all three read methods now call `$query->with(['generalSettings', 'productSettings'])->...` and map via the new `toConfiguredDomain()` model method. Write path (`entityToAttributes`, `getEntityIdentifier`, `getUpsertKeys`, `saveMany`) untouched — still operates on `CustomFieldDefinition`.
- `app/Infrastructure/Shopwired/Models/CustomFieldDefinitionModel.php` — add `generalSettings(): HasOne` and `productSettings(): HasOne` relations pointing at the new catalog-schema models. Add `toConfiguredDomain(): ConfiguredFieldDefinition` that: (a) calls existing `toDomain()` for the ShopWired core definition, (b) reads `generalSettings` relation (defaulting to `CustomFieldGeneralSettings::defaults()` if missing), (c) reads `productSettings` only when `$this->item_type === CustomFieldItemType::Product->value` (null otherwise).
- `app/Infrastructure/Shopwired/CustomFields/CustomFieldDefinitionRegistry.php` — change type params from `CustomFieldDefinition` to `ConfiguredFieldDefinition`. Internal filter becomes `$definition->base->itemType === $itemType`; registry keying by `$definition->base->name`.
- `app/Infrastructure/Shopwired/Factories/CustomFieldFactory.php` — signature changes propagate through `registry()` and `fromRawFields()`. No behavioural change.
- `app/Infrastructure/Shopwired/Factories/CustomFieldValueFactory.php` — `createTypedValueFromDefinition()` accepts `ConfiguredFieldDefinition`; internal helpers read `$definition->base->type`, `$definition->base->name`, `$definition->base->hasAllowedValues()`, `$definition->base->isValueAllowed($v)`. Concrete `*CustomFieldValue` VO constructors receive the configured wrapper directly (they unpack `->base` as needed internally).
- `app/Application/Catalog/CustomFieldMergerService.php` — `mergeWithDefinitions()` second param becomes `list<ConfiguredFieldDefinition>`. `NullCustomFieldValue` is constructed with the configured wrapper. The `usort` callback at lines 51–52 becomes `$a->definition->base->sortOrder` / `$b->definition->base->sortOrder` (one extra `->base` hop; property access, not method call).
- `app/Providers/ShopwiredServiceProvider.php` — no binding changes; the existing factory bindings still work because only the types flowing through them changed.

### Tests to update

- All value-object unit tests (`tests/Unit/Domain/Catalog/CustomFields/ValueObjects/*CustomFieldValueTest.php`) — helper constructs a `CustomFieldDefinition`, now wrap it with `new ConfiguredFieldDefinition($def, CustomFieldGeneralSettings::defaults(), null)`. Add a small private helper `makeConfiguredDefinition()` inside each test file (or a shared trait in `tests/Unit/Domain/Catalog/CustomFields/` — no `tests/Support` directory exists in this project).
- `tests/Unit/Infrastructure/Shopwired/Factories/CustomFieldValueFactoryTest.php` — same wrap.
- `tests/Unit/Application/Catalog/` — any merger-service tests updated.
- Add feature test: `tests/Feature/Infrastructure/Shopwired/EloquentCustomFieldRepositoryTest.php` covering eager-load hydration with and without settings rows.

## Critical Files (paths)

| File | Purpose |
|---|---|
| `app/Domain/Catalog/CustomFields/ValueObjects/CustomFieldDefinition.php` | Untouched — write contract |
| `app/Domain/Catalog/CustomFields/ValueObjects/AbstractCustomFieldValue.php` | Embed `ConfiguredFieldDefinition` |
| `app/Infrastructure/Shopwired/Models/CustomFieldDefinitionModel.php` | New `hasOne` + `toConfiguredDomain()` |
| `app/Infrastructure/Shopwired/Repositories/EloquentCustomFieldRepository.php` | Eager-load + new return type |
| `app/Infrastructure/Shopwired/Factories/CustomFieldFactory.php` | Signature ripple |
| `app/Infrastructure/Shopwired/Factories/CustomFieldValueFactory.php` | Signature ripple |
| `app/Infrastructure/Shopwired/CustomFields/CustomFieldDefinitionRegistry.php` | Holds configured VOs |
| `app/Application/Catalog/CustomFieldMergerService.php` | Accepts configured list |

## Patterns/Utilities to Reuse

- `CustomFieldDefinitionModel::fromDomainAttributes()` (`app/Infrastructure/Shopwired/Models/CustomFieldDefinitionModel.php:119`) — canonical shape for the new settings models' `fromDomainAttributes()`. Exclude the FK (set by the repository in future write work).
- `OrderRefundModel` (`app/Infrastructure/Shopwired/Models/OrderRefundModel.php`) — template for child-table migration (FK + cascade) and Eloquent model with a `BelongsTo`.
- `ProductViewAssembler::resolveVariations()` / `relationLoaded()` pattern (`app/Infrastructure/Catalog/Product/Mappers/ProductViewAssembler.php:54`) — guard pattern for reading eager-loaded relations. `toConfiguredDomain()` follows this exact shape.
- `CustomFieldType` and `CustomFieldItemType` (`app/Domain/Catalog/CustomFields/Enums/`) — canonical string-backed enum shape for the new string enums.
- `CustomFieldDefinitionModel::toDomain()` — fail-loud pattern for unknown enum values (`CustomFieldValidationRule::tryFrom(...)` → `InvalidApiResponseException` at CRITICAL log).
- `CustomFieldDefinitionRegistry::forItemType()` — static factory pattern; stays identical apart from VO type.

## Verification

1. **Migrations run cleanly**
   - `php artisan migrate` creates both tables; `\d catalog.custom_field_general_settings` shows the unique FK to `shopwired.custom_field_definitions(id)` with ON DELETE CASCADE.
   - Rollback (`php artisan migrate:rollback --step=2`) drops both tables cleanly.
2. **Read path smoke**
   - In tinker: insert a `CustomFieldGeneralSettingsModel` row for one Product-type definition with `admin_only=true, tooltip='test'`. Call `app(CustomFieldRepositoryInterface::class)->findByName('<name>')` — assert the returned `ConfiguredFieldDefinition` has the settings populated.
   - Call `findByName('<name>')` for a definition with **no** settings row — assert `generalSettings` equals `CustomFieldGeneralSettings::defaults()` and `productSettings` is null (or populated if product-typed and a product settings row exists).
3. **API payload regression (no shape change)**
   - `curl -H "X-Local-Bypass: $API_BYPASS_SECRET" 'http://localhost/api/products/<id>'` — diff the `customFields` portion of the response against a pre-migration capture. Keys must be identical (`name, type, label, value, allowed_values, sort_order`); no new `tooltip` / `admin_only` / `select_type` keys should appear. Surfacing those is a future PR once admin-role and frontend consumers exist.
4. **Type safety**
   - `make lint` passes (Pint + PHPStan level max + PHPArkitect + Deptrac + TLint). PHPStan will validate the read-path ripple is fully typed.
5. **Tests**
   - `make test` — all updated unit tests pass; the new repository feature test covers the eager-load behaviour.
6. **Write path regression**
   - `php artisan app:sync-custom-fields` still runs against ShopWired and upserts definitions — confirms `saveMany(CustomFieldDefinition)` path was not touched.
