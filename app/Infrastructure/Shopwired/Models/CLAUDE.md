# ShopWired Eloquent Models

## Domain Mapping

**All models must implement `EloquentDomainMappableInterface`.**

### Simple Models → Use `AutoDomainMappingTrait` Trait

For 1:1 property mappings (only snake_case ↔ camelCase differences):

```php
use App\Infrastructure\Concerns\AutoDomainMappingTrait;

final class OrderDiscountModel extends Model implements EloquentDomainMappableInterface
{
    use AutoDomainMappingTrait;

    protected function domainClass(): string
    {
        return OrderDiscount::class;
    }
}
```

### Complex Models → Manual Implementation

Don't use the trait when you have:
- Nested value objects (e.g., `ProductVariation`)
- Enum conversions
- Property name differences beyond case (`id` ↔ `external_id`)
- Array structure transformations

### Model Defaults

- `protected $guarded = [];` — Internal sync models don't receive user input
- `protected $table = 'shopwired.table_name';` — Explicit schema-qualified name

### Child Table Relationships

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