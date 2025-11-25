# ReviewsIo API Client - Architecture Notes

## Response Parsing Pattern: Private Helper Methods

This client uses **typed private helper methods** for response parsing, designed to scale to 10-20+ endpoints.

### Current Helpers

```php
parseArrayResponse(mixed $data, string $dtoClass): DataCollection
```
- For endpoints returning an array of DTOs
- Uses PHPDoc generics (`@template T of Data`) for type inference

```php
logParsingFailure(string $error, mixed $data): void
```
- Centralized logging for parsing failures
- Logs at CRITICAL level with raw response for debugging API contract changes

### Adding New Endpoints

Follow this pattern when adding endpoints:

```php
// Array response (list of items):
return $this->parseArrayResponse($response->json(), Rating::class);

// Nested response (add helper when needed):
return $this->parseNestedResponse($response->json(), 'reviews', Review::class);

// Single object (add helper when needed):
return $this->parseSingleResponse($response->json(), Product::class);
```

### Why Private Helpers (Not Traits)

- **Self-contained**: Each API client is independent, no coupling
- **Flexible**: Different APIs have different response shapes
- **Type-safe**: PHPDoc generics work better with concrete methods than callables
- **Template pattern**: Copy and adapt helpers to new clients as needed

### Constants

- `SERVICE_NAME`: Used in logging for consistent service identification
- `ENDPOINT_*`: API endpoint paths extracted to constants
