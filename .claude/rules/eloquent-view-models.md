---
paths:
  - "app/Infrastructure/**/Models/*ViewModel.php"
---

# Eloquent View-Model Rules

ViewModels are read-only Eloquent models backed by PostgreSQL views (read projections, not write targets).

## Boilerplate

- DO declare the class `final` extending `Model`
- DO set `public $timestamps = false;` — views have no `created_at`/`updated_at`
- DO set `public $incrementing = false;` + `protected $keyType = 'string';` for UUID-keyed views
- DO use a `casts()` method with `#[Override]`
- DO set `$table` schema-qualified to the view (e.g. `'catalog.orders_view'`)
- DO document each view column with `/** @property ... */` for IDE autocomplete and PHPStan

## Write Prohibitions

- DO NOT implement `EloquentDomainMappableInterface`
- DO NOT call `save()`, `update()`, `insert()`, or `delete()` — use the paired write `*Model` for writes
