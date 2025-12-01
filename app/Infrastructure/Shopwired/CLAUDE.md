# ShopWired Infrastructure Layer

## Response Parsing

**Always use `ShopwiredResponseParserTrait`** when returning data from API endpoints.

### Available Methods

| Method | Use When |
|--------|----------|
| `parseArrayToDomain($data, DTO::class)` | Array response → Domain objects |
| `parseSingleToDomain($data, DTO::class)` | Single object → Domain object |
| `parseWrappedArrayToDomain($data, DTO::class)` | Wrapped response `{items: [...]}` → Domain objects |
| `parseCountResponse($data)` | Count endpoint `{count: n}` → int |

### DTO Requirements

DTOs must:
1. Extend `Spatie\LaravelData\Data`
2. Implement `DomainConvertible` interface
3. Have a `toDomain()` method returning the Domain value object

### Example

```php
// Direct array response
return self::parseArrayToDomain($response->json(), Order::class);

// Wrapped response (e.g., search endpoints)
return self::parseWrappedArrayToDomain($response->json(), Order::class);

// Single object
return self::parseSingleToDomain($response->json(), Order::class);
```

## Two-Mode Pattern (Orders)

Orders use Standard vs Detail modes:
- **Standard**: Lightweight (no `products`/`customFields`)
- **Detail**: Complete data (all fields)

See `OrderClient.php` constants: `STANDARD_FIELDS`, `DETAIL_FIELDS`, `STANDARD_EMBEDS`, `DETAIL_EMBEDS`.
