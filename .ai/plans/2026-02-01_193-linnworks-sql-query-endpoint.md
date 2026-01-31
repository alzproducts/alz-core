# Plan: Linnworks SQL Query Endpoint (Dashboards API)

## Context

This implements the Linnworks `Dashboards/ExecuteCustomScriptQuery` endpoint to resolve **BLOCKER: Linnworks Soft-Delete SKU Collision** from issue #186.

**Problem:** `AddInventoryItem` with a SKU matching a soft-deleted item returns 204 but doesn't create. `GetStockItemIdsBySKU` doesn't return soft-deleted items, so we can't detect collisions.

**Solution:** Query the Linnworks database directly via SQL to check for SKU existence including soft-deleted items.

---

## Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Query architecture | Query Object + Facade | Self-contained queries, clean Application interface |
| Enforcement | Abstract class with final method | Template Method pattern ensures isolation level always applied |
| SQL escaping | Centralized utilities | Consistent, tested escaping in `SqlQueryBuilder` |
| Return type | `array<string, Guid>` | SKU→stockItemId mapping, minimal for existence checking |
| Generic typing | PHPStan @template | Type-safe query execution with inferred return types |
| Pagination | Deferred | Current use case (40 SKUs max) doesn't need it |

---

## Architecture Overview

```
Application/Contracts/
└── StockDashboardsClientInterface.php    ← Clean typed interface

Infrastructure/Linnworks/
├── Queries/
│   ├── LinnworksQueryInterface.php       ← @template TResult
│   ├── AbstractLinnworksQuery.php        ← Enforces isolation level (final)
│   └── StockItemBySkuQuery.php           ← Self-contained query
├── Clients/
│   ├── DashboardsClient.php              ← execute(QueryInterface) - internal
│   └── StockDashboardsClient.php         ← Facade using queries
├── Responses/
│   ├── SqlQueryResponse.php              ← API response DTO
│   └── SqlQueryColumnResponse.php        ← Column metadata
└── Support/
    └── SqlQueryBuilder.php               ← Escaping utilities
```

**Flow:**
```
Application UseCase → StockDashboardsClientInterface::findStockItemsBySku()
                    → StockDashboardsClient (facade)
                    → DashboardsClient::execute(StockItemBySkuQuery)
                    → Transport::post() → Linnworks API
```

---

## Files to Create

### Application Layer

**1. `app/Application/Contracts/Linnworks/StockDashboardsClientInterface.php`**
```php
interface StockDashboardsClientInterface
{
    /**
     * Find stock items by SKU, including soft-deleted items.
     *
     * @param list<string> $skus
     * @return array<string, Guid> SKU => stockItemId (only SKUs that exist)
     */
    public function findStockItemsBySku(array $skus): array;
}
```

### Infrastructure Layer - Queries

**2. `app/Infrastructure/Linnworks/Queries/LinnworksQueryInterface.php`**
```php
/**
 * A self-contained Linnworks SQL query with typed response mapping.
 *
 * @template TResult
 */
interface LinnworksQueryInterface
{
    /**
     * Build the complete SQL query (including isolation level).
     */
    public function buildSql(): string;

    /**
     * Map the raw query response to a typed result.
     *
     * @return TResult
     */
    public function mapResponse(SqlQueryResponse $response): mixed;
}
```

**3. `app/Infrastructure/Linnworks/Queries/AbstractLinnworksQuery.php`**
```php
/**
 * Base class for Linnworks SQL queries.
 *
 * Enforces consistent query building via Template Method pattern:
 * - buildSql() is final, always applies isolation level
 * - Subclasses implement buildQueryBody() for the actual SQL
 *
 * @template TResult
 * @implements LinnworksQueryInterface<TResult>
 */
abstract readonly class AbstractLinnworksQuery implements LinnworksQueryInterface
{
    /**
     * Build complete SQL with isolation level prefix.
     * Final to enforce consistent behavior across all queries.
     */
    final public function buildSql(): string
    {
        return SqlQueryBuilder::withIsolationLevel($this->buildQueryBody());
    }

    /**
     * Build the query body WITHOUT isolation level prefix.
     * Implementers provide only the actual SQL.
     */
    abstract protected function buildQueryBody(): string;
}
```

**4. `app/Infrastructure/Linnworks/Queries/StockItemBySkuQuery.php`**
```php
/**
 * Query stock items by SKU, including soft-deleted items.
 *
 * @extends AbstractLinnworksQuery<array<string, Guid>>
 */
final readonly class StockItemBySkuQuery extends AbstractLinnworksQuery
{
    /**
     * @param list<string> $skus SKUs to look up
     */
    public function __construct(
        private array $skus,
    ) {}

    protected function buildQueryBody(): string
    {
        if ($this->skus === []) {
            throw new InvalidArgumentException('SKU list cannot be empty');
        }

        $inClause = SqlQueryBuilder::buildInClause($this->skus);

        return "SELECT pkStockItemID, ItemNumber FROM StockItem WHERE ItemNumber IN {$inClause}";
    }

    /**
     * @return array<string, Guid>
     */
    public function mapResponse(SqlQueryResponse $response): array
    {
        $results = [];

        foreach ($response->results as $row) {
            $sku = (string) $row['ItemNumber'];
            $results[$sku] = Guid::fromString((string) $row['pkStockItemID']);
        }

        return $results;
    }
}
```

### Infrastructure Layer - Support

**5. `app/Infrastructure/Linnworks/Support/SqlQueryBuilder.php`**
```php
/**
 * SQL query building utilities for Linnworks SQL Server queries.
 *
 * Provides consistent escaping and query construction helpers.
 */
final class SqlQueryBuilder
{
    private const TRANSACTION_ISOLATION = 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;';

    /**
     * Wrap SQL with transaction isolation level prefix.
     */
    public static function withIsolationLevel(string $sql): string
    {
        return self::TRANSACTION_ISOLATION . ' ' . $sql;
    }

    /**
     * Escape a single string value for SQL Server.
     * Handles single quote escaping.
     */
    public static function escapeString(string $value): string
    {
        return "'" . str_replace("'", "''", $value) . "'";
    }

    /**
     * Build IN clause from string array.
     *
     * @param list<string> $values
     * @throws InvalidArgumentException When values array is empty
     */
    public static function buildInClause(array $values): string
    {
        if ($values === []) {
            throw new InvalidArgumentException('IN clause values cannot be empty');
        }

        $escaped = array_map(self::escapeString(...), $values);

        return '(' . implode(', ', $escaped) . ')';
    }

    /**
     * Build IN clause from GUIDs.
     *
     * @param list<Guid> $guids
     * @throws InvalidArgumentException When guids array is empty
     */
    public static function buildGuidInClause(array $guids): string
    {
        $strings = array_map(
            static fn(Guid $guid): string => $guid->toString(),
            $guids
        );

        return self::buildInClause($strings);
    }
}
```

### Infrastructure Layer - Responses

**6. `app/Infrastructure/Linnworks/Responses/SqlQueryResponse.php`**
```php
#[MapInputName(PascalCaseMapper::class)]
final readonly class SqlQueryResponse extends Data
{
    /**
     * @param list<SqlQueryColumnResponse> $columns
     * @param list<array<string, mixed>> $results
     */
    public function __construct(
        public bool $isError,
        public int $totalResults,
        #[DataCollectionOf(SqlQueryColumnResponse::class)]
        public array $columns,
        public array $results,
    ) {}
}
```

**7. `app/Infrastructure/Linnworks/Responses/SqlQueryColumnResponse.php`**
```php
#[MapInputName(PascalCaseMapper::class)]
final readonly class SqlQueryColumnResponse extends Data
{
    public function __construct(
        public string $name,
        public string $type,
    ) {}
}
```

### Infrastructure Layer - Clients

**8. `app/Infrastructure/Linnworks/Clients/DashboardsClient.php`** (internal)
```php
/**
 * Low-level Linnworks Dashboards API client.
 *
 * Executes query objects against the Dashboards/ExecuteCustomScriptQuery endpoint.
 * NOT for direct use - compose into category-specific facade clients.
 *
 * @internal
 */
final readonly class DashboardsClient
{
    private const ENDPOINT = 'Dashboards/ExecuteCustomScriptQuery';

    public function __construct(
        private LinnworksTransportInterface $transport,
    ) {}

    /**
     * Execute a query object and return typed result.
     *
     * @template TResult
     * @param LinnworksQueryInterface<TResult> $query
     * @return TResult
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function execute(LinnworksQueryInterface $query): mixed
    {
        $sql = $query->buildSql();
        $response = $this->transport->post(self::ENDPOINT, ['Script' => $sql]);

        try {
            $dto = SqlQueryResponse::from($response->json());
        } catch (CannotCreateData $e) {
            Log::critical('Linnworks SQL query response validation failed', [
                'error' => $e->getMessage(),
                'response' => $response->json(),
            ]);
            throw new InvalidApiResponseException('Linnworks SQL query response malformed');
        }

        if ($dto->isError) {
            throw new InvalidApiResponseException('Linnworks SQL query returned error');
        }

        return $query->mapResponse($dto);
    }
}
```

**9. `app/Infrastructure/Linnworks/Clients/StockDashboardsClient.php`**
```php
/**
 * Stock-related queries via Linnworks Dashboards SQL API.
 *
 * Facade providing typed methods for Application layer,
 * internally using query objects for self-contained SQL/mapping.
 */
final readonly class StockDashboardsClient implements StockDashboardsClientInterface
{
    public function __construct(
        private DashboardsClient $dashboardsClient,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws InvalidApiResponseException When query fails
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function findStockItemsBySku(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        return $this->dashboardsClient->execute(new StockItemBySkuQuery($skus));
    }
}
```

### Files to Modify

**10. `app/Infrastructure/Linnworks/LinnworksClientFactory.php`**
```php
// Add methods:
public function createDashboardsClient(): DashboardsClient
{
    return new DashboardsClient($this->getTransport());
}

public function createStockDashboardsClient(): StockDashboardsClient
{
    return new StockDashboardsClient($this->createDashboardsClient());
}
```

**11. `app/Providers/LinnworksServiceProvider.php`**
```php
// Add binding:
$this->app->bind(
    StockDashboardsClientInterface::class,
    fn($app) => $app->make(LinnworksClientFactory::class)->createStockDashboardsClient()
);
```

---

## Verification Plan

1. **Unit Tests - SqlQueryBuilder:**
   - `escapeString()` - single quotes, empty string, special chars
   - `buildInClause()` - normal array, empty array throws
   - `withIsolationLevel()` - prefix applied correctly

2. **Unit Tests - StockItemBySkuQuery:**
   - `buildSql()` - correct SQL generated with isolation level
   - `buildSql()` - empty array throws
   - `mapResponse()` - maps results correctly

3. **Integration Tests (mocked transport):**
   - `StockDashboardsClient::findStockItemsBySku()` - happy path
   - `StockDashboardsClient::findStockItemsBySku()` - empty results
   - `StockDashboardsClient::findStockItemsBySku()` - empty input returns []
   - `DashboardsClient::execute()` - IsError = true throws

4. **Manual Verification:**
   ```php
   // In tinker
   $client = app(StockDashboardsClientInterface::class);
   $results = $client->findStockItemsBySku(['1005821', 'NONEXISTENT']);
   dump($results);
   // Should return ['1005821' => Guid(...)] if exists
   ```

---

## Implementation Order

1. Create `SqlQueryBuilder` support class
2. Create `SqlQueryResponse` + `SqlQueryColumnResponse` DTOs
3. Create `LinnworksQueryInterface`
4. Create `AbstractLinnworksQuery`
5. Create `StockItemBySkuQuery`
6. Create `DashboardsClient` (internal)
7. Create `StockDashboardsClientInterface` contract
8. Create `StockDashboardsClient` facade
9. Update `LinnworksClientFactory` with factory methods
10. Update `LinnworksServiceProvider` with binding
11. Write unit tests
12. Write integration tests

---

## Future Extensibility

**Adding New Queries:**
1. Create query class extending `AbstractLinnworksQuery<ReturnType>`
2. Implement `buildQueryBody()` and `mapResponse()`
3. Add method to appropriate facade client
4. Done - isolation level enforcement is automatic

**Example - Future Query:**
```php
/**
 * @extends AbstractLinnworksQuery<array<string, int>>
 */
final readonly class DuplicateSkusQuery extends AbstractLinnworksQuery
{
    protected function buildQueryBody(): string
    {
        return "SELECT ItemNumber, COUNT(*) as Count FROM StockItem GROUP BY ItemNumber HAVING COUNT(*) > 1";
    }

    /** @return array<string, int> SKU => count */
    public function mapResponse(SqlQueryResponse $response): array { ... }
}
```

**Other Query Categories:**
- `OrderDashboardsClient` → order-related queries
- `ReportDashboardsClient` → reporting queries

All compose `DashboardsClient` and use query objects.
