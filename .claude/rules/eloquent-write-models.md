---
paths:
  - "app/Infrastructure/**/Models/*Model.php"
  - "!app/Infrastructure/**/Models/*ViewModel.php"
---

# Eloquent Write-Model Rules

> This rule applies to write-path Eloquent models only. For read-only `*ViewModel.php` files, see `eloquent-view-models.md`.

## Domain Mapping

- DO implement `EloquentDomainMappableInterface` on every write model
- EXCEPTION: models with complex conversion (see §Complex Models) delegate to a dedicated mapper class instead

### Simple Models → Use `AutoDomainMappingTrait`

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

### Complex Models → Manual Implementation or Dedicated Mapper

Don't use the trait when you have:
- Nested value objects (e.g., `ProductVariation`)
- Enum conversions
- Property name differences beyond case (`id` ↔ `external_id`)
- Array structure transformations

For complex models needing dedicated mapping classes, use a `*ModelMapper` under `app/Infrastructure/{Integration}/Mappers/`. Use `App\Infrastructure\Concerns\MapperHelperTrait` for enum parsing with fallback logging.

## Model Defaults

- `protected $guarded = [];` — Internal sync models don't receive user input
- `protected $table = 'schema.table_name';` — **Always schema-qualified.** Eloquent defaults to the `public` schema otherwise, causing missing-table errors in this project (multi-schema layout: `shopwired.*`, `linnworks.*`, `catalog.*`, `access.*`, etc.)

## Attribute Mapping Method

Provide a `Model::attributesFromDomain($vo)` static method so repositories can avoid inline field-by-field mapping. Canonical example: `StockItemSupplierModel::attributesFromDomain()`.

- DO NOT include the parent FK — the repository adds it via spread
- DO include `created_at`/`updated_at` timestamps — bulk `insert()` bypasses Eloquent timestamps
