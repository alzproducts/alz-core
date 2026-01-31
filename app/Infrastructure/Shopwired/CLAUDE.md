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

## Embed Return Formats

**Embeds return different formats depending on the embed type.** Always check API documentation for each embed.

Examples:
- `categories` embed → returns full category objects `[{id, title, ...}]`
- Other embeds may return ID arrays, nested objects, or different structures

When adding a new embed, verify the actual response structure before writing the DTO.

## Update Semantics

**Behaviour varies per endpoint.** Some use PATCH semantics (missing = unchanged), others require all fields (missing = deleted). Test each update endpoint manually before implementing.

## Database Schema

ShopWired data is stored in the `shopwired` schema (not `public`). Use qualified table names in queries.

| Table | Description |
|-------|-------------|
| `shopwired.products` | Product catalog data |
| `shopwired.product_variations` | Product variations with SKU, prices |
| `shopwired.orders` | Order headers |
| `shopwired.order_products` | Order line items |
| `shopwired.order_discounts` | Applied discounts |
| `shopwired.order_refunds` | Refund records |
| `shopwired.order_admin_comments` | Internal order notes |
| `shopwired.customers` | Customer records |
| `shopwired.custom_field_definitions` | Custom field metadata |
| `shopwired.orders_deduplicated` | **View**: One order per reference (for bulk queries) |

### Querying Examples

```php
// Direct DB query (use schema prefix)
DB::table('shopwired.products')->where('external_id', 12345)->first();

// Via Eloquent (if model configured with schema)
Product::where('external_id', 12345)->first();
```
