---
paths:
  - "app/Infrastructure/**/Models/*ViewModel.php"
---

# Eloquent View-Model Rules

ViewModels are **read-only** Eloquent models backed by PostgreSQL views (e.g. `catalog.products_view`). They are read projections, NOT write targets.

## Conventions

- **DO NOT implement `EloquentDomainMappableInterface`** — ViewModels are read projections, not write targets
- **DO NOT call** `save()`, `update()`, `insert()`, or `delete()`. Use the paired write model (e.g. `ProductModel` for `ProductViewModel`) for writes
- DO set `protected $table = 'schema.thing_view';` — schema-qualified, pointing at the view
- DO document each view column with `/** @property ... */` for IDE autocomplete and PHPStan support

## Pairing

Every ViewModel has a corresponding write `*Model.php`. ViewModel reads derived data (joins, computed columns from views); the write model owns inserts/updates to the base table.

| Read (ViewModel) | Write (Model) |
|---|---|
| `ProductViewModel` → `catalog.products_view` | `ProductModel` → `shopwired.products` |
| `OrderViewModel` → `catalog.orders_view` | `OrderModel` → `shopwired.orders` |
| `CustomerViewModel` → `catalog.customers_view` | `CustomerModel` → `shopwired.customers` |
