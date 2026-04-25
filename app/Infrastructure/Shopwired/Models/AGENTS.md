# ShopWired Eloquent Models

> General Eloquent model conventions (`EloquentDomainMappableInterface`, `AutoDomainMappingTrait`, `$guarded`, schema-qualified `$table`) → `.claude/rules/eloquent-write-models.md` (auto-loads on `*Model.php`)
>
> ViewModel conventions → `.claude/rules/eloquent-view-models.md` (auto-loads on `*ViewModel.php`)

## Child Table Relationships

Child tables need **two columns** linking to parent:
- `order_id` (uuid) — FK to `orders.id` with cascade delete
- `order_external_id` (int) — Parent's ShopWired ID, indexed for queries

**Sync strategy**: All child tables use delete-all → insert-all (no upsert). None have stable unique line item IDs.

### Sync Deletes

**Always delete by parent's external ID column**, never by UUID — external IDs are stable across syncs.

## Mappers

For complex models needing dedicated mapping classes:

- Example: `app/Infrastructure/Shopwired/Mappers/OrderModelMapper.php`
- Use `app/Infrastructure/Concerns/MapperHelperTrait` for enum parsing with fallback logging