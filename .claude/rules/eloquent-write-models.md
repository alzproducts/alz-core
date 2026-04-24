---
paths:
  - "app/Infrastructure/**/Models/*Model.php"
  - "!app/Infrastructure/**/Models/*ViewModel.php"
---

# Eloquent Write-Model Rules

> Read-only `*ViewModel.php` files → `eloquent-view-models.md`.

## Boilerplate

- DO declare the class `final` extending `Model`
- DO `use HasUuids;` for UUID primary keys
- DO use a `casts()` method with `#[Override]`, NOT the `$casts` property
- DO cast timestamps as `immutable_datetime`, never `datetime`
- DO set `$table` schema-qualified (`'schema.table'`) — this project is multi-schema; Eloquent otherwise defaults to `public`
- DO set `protected $guarded = [];` — internal sync models don't receive user input

## Domain Mapping

- DO implement `EloquentDomainMappableInterface`
- DO use `AutoDomainMappingTrait` for 1:1 snake↔camel property mappings (implement only `domainClass()`)
- DO NOT use the trait when the model has nested VOs, enum conversions, property-name differences beyond case, or array-shape transformations — delegate to a `*ModelMapper` class under `app/Infrastructure/{Integration}/Mappers/` (use `MapperHelperTrait` for enum parsing with fallback logging)

## Attribute Mapping Method

- DO name the method `fromDomainAttributes(object $entity): array` when implementing the interface (`AutoDomainMappingTrait` generates it)
- DO name it `attributesFromDomain(SpecificType $t): array` for non-interface models (typed parameter)
- DO NOT include the parent FK or upsert key — the repository adds it via spread
- DO include `created_at`/`updated_at` if the mapper feeds `insertMany()` — bulk insert bypasses Eloquent timestamps
