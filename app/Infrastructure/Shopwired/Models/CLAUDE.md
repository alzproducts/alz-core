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