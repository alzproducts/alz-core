# Linnworks Infrastructure

## Tinker Usage

```php
// Use interface bindings (direct class instantiation fails)
$client = app(InventoryClientInterface::class);
```

## API Format Reference

**Linnworks docs are often wrong.** The legacy `alz-connect` project has working implementations.

**Legacy location:** `/Users/tom/code/alz-connect/legacy/src/`
- `Alz/ProductsA.php` - Stock/inventory endpoints
- `Linnworks/LinnFactory.php` - HTTP transport

## Request Formats

Linnworks uses inconsistent formats across endpoints:

| Format | Transport Method | Example |
|--------|------------------|---------|
| JSON wrapper | `post()` | `request={JSON}` |
| Raw form params | `postFormParams()` | `key=val&arr=["json"]` |

**When 400 Bad Request**: Check legacy project for correct format.

## Known Quirks

- `ProperyName` typo in Extended Properties response (missing 't')
- `ItemExtendedProperties` vs `ExtendedProperties` varies by endpoint
- Default location ID: `00000000-0000-0000-0000-000000000000`
