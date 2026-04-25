# Linnworks Queries

## Two Classes Per File Strategy

Query classes in this directory use a **co-located Row DTO pattern**: the `*Row` data class and `*Query` class live in the same file.

**Rationale:**
- Row DTOs are internal implementation details of their Query
- Keeps related code together, reducing cognitive load
- Row classes are never used outside their Query

## Template

Use `StockItemBySkuQuery.php` as the canonical template when creating new queries.

**Structure:**
```php
// 1. Row DTO (internal, marked @internal)
final class ExampleRow extends Data
{
    public function __construct(
        #[MapInputName('ColumnName')]
        public readonly string $field,
    ) {}
}

// 2. Query class
final readonly class ExampleQuery extends AbstractLinnworksQuery
{
    public function __construct(private array $params) {}

    protected function buildQueryBody(): string { /* SQL */ }

    public function mapResponse(SqlQueryResponse $response): mixed { /* parse rows */ }
}
```

## Naming Convention

- Query: `{Purpose}Query` (e.g., `StockItemBySkuQuery`)
- Row: `{Purpose}Row` (e.g., `StockItemBySkuRow`)
