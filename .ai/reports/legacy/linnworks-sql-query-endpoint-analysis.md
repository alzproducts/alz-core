# Linnworks SQL Query Endpoint Analysis

**Analysis Date:** 2026-01-31
**Source Codebase:** `/Users/tom/code/alz-connect`
**Target Codebase:** `/Users/tom/code/IdeaProjects/alz-core-two`

---

## Executive Summary

The legacy codebase executes raw SQL queries against the Linnworks database via the **Dashboards API**. This endpoint (`ExecuteCustomScriptQuery`) bypasses the standard inventory APIs and can query soft-deleted items directly.

**Key Finding:** The soft-delete column is `bLogicalDelete`, NOT `bDeleted`.

---

## 1. Entry Points & Usage

### Primary Class

**File:** `legacy/src/Api/Linn2/src/Endpoint/Query.php`

```php
namespace Linn2\Endpoint;

final class Query extends AbstractEndpoint implements ICanExecuteQuery
{
    public const EXECUTE_SCRIPT_URL = 'Dashboards/ExecuteCustomScriptQuery';

    public function query(string $query): array
    {
        return $this->execute($query)->getResults();
    }

    public function execute(string $query, ?string $friendlyName = null): QueryResult
    {
        $lwQuery = new LwQuery($query);
        $results = $this->apiClient->get(
            self::EXECUTE_SCRIPT_URL,
            StdOrNull::class,
            $lwQuery->getQuery()
        );
        return (new QueryResult($lwQuery))->hydrate($results ?? null);
    }
}
```

### Invocation Methods

1. **Via LinnApiClient**
   ```php
   // From DI container
   $linnApi = $container->get(LinnApiClient::class);
   $results = $linnApi->queries()->query("SELECT * FROM StockItem WHERE ItemNumber = '1005821'");
   ```

2. **Via QueryLinnService (Alternative)**
   ```php
   $queryService = $container->get(QueryLinnService::class);
   $result = $queryService->execute("SELECT pkStockItemID FROM StockItem WHERE ItemNumber = '1005821'");
   ```

3. **Via QueryApiController (Abstract Base Class)**
   - Used for cached, structured queries
   - File: `legacy/src/Mvc/Model/QueryResult/Linnworks/QueryApiController.php`

### Example Usages in Codebase

| File | Purpose |
|------|---------|
| `legacy/src/Mvc/Model/Table/Linnworks/Tables/*.php` | Dynamic report tables |
| `legacy/src/Alz/Pages/UniqueProps/FindAllIncorrectProps.php` | Product property validation |
| `legacy/src/Mvc/Service/LwApi/Query/*.php` | Query service controllers |

---

## 2. API Communication

### Endpoint Details

| Property | Value |
|----------|-------|
| **Endpoint Path** | `/api/Dashboards/ExecuteCustomScriptQuery` |
| **HTTP Method** | `POST` |
| **Content-Type** | `application/x-www-form-urlencoded; charset=UTF-8` |

### Full URL Pattern

```
{server}/api/Dashboards/ExecuteCustomScriptQuery
```

### Key Header Detail

**Important:** The token is passed **raw** in the `Authorization` header - NOT as `Bearer {token}`.

```
Authorization: abc123xyz
```
NOT:
```
Authorization: Bearer abc123xyz
```

---

## 3. Request Format

### Form Parameters

The request body uses `form_params` (URL-encoded form data):

**File:** `legacy/src/AlzMvc/Service/Linnworks/Query/QueryLinnService.php:60-78`

```php
public static function createQueryFormParamsFull(string $query): array
{
    return ['form_params' => self::createRequestFormParams($query)];
}

private static function createRequestFormParams(string $queryString): array
{
    return [
        'request' => json_encode(
            ['Script' => $queryString],
            JSON_THROW_ON_ERROR
        ),
    ];
}
```

### Request Body Structure

```
request={"Script":"SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED; SELECT * FROM StockItem WHERE ItemNumber = '1005821'"}
```

**Decoded:**
```json
{
  "Script": "SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED; SELECT * FROM StockItem WHERE ItemNumber = '1005821'"
}
```

### Query Wrapping (Automatic)

**File:** `legacy/src/Api/Linn2/src/Model/LwQuery.php`

```php
private const TRANSACTION_ISO_LEVEL = 'SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;';

public function __construct(string $query)
{
    $this->query = $query;
    $this->addTransactionLevel();  // Auto-prepends isolation level
}

public function addTransactionLevel(): self
{
    $this->query = self::TRANSACTION_ISO_LEVEL . ' ' . $this->query;
    return $this;
}
```

**Purpose:** The `READ UNCOMMITTED` isolation level allows reading uncommitted changes and improves performance by avoiding locks.

### Character Encoding Considerations

The `LwQuery` class has optional encoding for special characters:

```php
private const SEARCH_FOR = ['+', '&'];
private const REPLACE_WITH = ['%2B', '%26'];

public function encodeQuery(): self
{
    $this->query = str_replace(self::SEARCH_FOR, self::REPLACE_WITH, $this->query);
    return $this;
}
```

**Note:** This encoding is currently **disabled** (commented out in the execute method).

---

## 4. Response Handling

### Raw API Response Structure

```json
{
  "IsError": false,
  "TotalResults": 1,
  "Columns": [
    { "Name": "pkStockItemID", "Type": "..." },
    { "Name": "ItemNumber", "Type": "..." }
  ],
  "Results": [
    {
      "pkStockItemID": "8db9c3dc-03ea-49b8-969d-9200b04fcaa5",
      "ItemNumber": "1005821"
    }
  ]
}
```

### QueryResult Model

**File:** `legacy/src/Api/Linn2/src/Model/QueryResult.php`

```php
final class QueryResult implements Extractable, HydratableObj, HasResults
{
    private $IsError;        // bool
    private $TotalResults;   // int
    private $Columns;        // stdClass[]
    private $Results;        // stdClass[]

    public function hydrate(?stdClass $data): self
    {
        if ($data !== null) {
            $this->setIsError((bool)$data->IsError);
            $this->setTotalResults((int)$data->TotalResults);
            $this->setColumns((array)$data->Columns);
            $this->setResults((array)$data->Results);
        }
        return $this;
    }

    // Utility methods
    public function getFirstRow(): ?stdClass { ... }
    public function asAssArray(): array { ... }
    public function getArrayColumn(string $columnName): array { ... }
}
```

### Error Response

On error, `IsError` will be `true`:

```json
{
  "IsError": true,
  "TotalResults": 0,
  "Columns": [],
  "Results": []
}
```

---

## 5. Database Schema Details

### StockItem Table Columns (Commonly Used)

| Column | Type | Description |
|--------|------|-------------|
| `pkStockItemID` | GUID | Primary key |
| `ItemNumber` | string | SKU |
| `ItemTitle` | string | Product title |
| `bLogicalDelete` | bit/bool | **SOFT DELETE FLAG** |
| `IsArchived` | bit/bool | Archive status |
| `CreationDate` | datetime | Creation timestamp |
| `ModifiedDate` | datetime | Last modification |
| `RetailPrice` | decimal | Retail price |
| `PurchasePrice` | decimal | Cost price |
| `CategoryId` | GUID | Category reference |
| `BarcodeNumber` | string | Barcode |
| `Weight`, `DimHeight`, `DimWidth`, `DimDepth` | decimal | Dimensions |
| `isVariationGroup` | bit/bool | Is variation parent |
| `bContainsComposites` | bit/bool | Is composite/bundle |

### Related Tables

| Table | Purpose |
|-------|---------|
| `StockItem_ExtendedProperties` | Custom properties/attributes |
| `StockItem_Variations` | Variation relationships |
| `ItemSupplier` | Supplier associations |
| `StockLevel` | Stock quantities by location |
| `ItemLocation` | Bin/rack locations |
| `ProductCategories` | Category definitions |
| `Supplier` | Supplier details |

---

## 6. Example Queries

### Check if SKU Exists (Including Soft-Deleted)

```sql
SELECT
    pkStockItemID,
    ItemNumber,
    ItemTitle,
    bLogicalDelete,
    IsArchived
FROM StockItem
WHERE ItemNumber = '1005821'
```

**Note:** Omit `AND bLogicalDelete = 0` to include soft-deleted items.

### Get Stock Item ID by SKU (Legacy Pattern)

**File:** `legacy/src/Linnworks/Sql/StockItem.php:255-265`

```php
public static function getPkStockId(
    string $sku,
    string $field = 'pkStockItemID',
    string $searchField = 'ItemNumber',
    bool $bLogicalDelete = false  // Set TRUE to include soft-deleted
): string
{
    $bLogical = empty($bLogicalDelete) ? ' AND bLogicalDelete = 0' : '';
    return "SELECT $field FROM [StockItem] WHERE $searchField = '$sku' $bLogical";
}
```

### Find All Items Including Soft-Deleted

```sql
SELECT
    pkStockItemID,
    ItemNumber,
    ItemTitle,
    bLogicalDelete AS 'IsDeleted',
    IsArchived
FROM StockItem
WHERE ItemNumber IN ('1005821', '1005822', '1005823')
```

### Check if Next SKU is Safe to Use

```sql
SELECT COUNT(*) AS 'Exists'
FROM StockItem
WHERE ItemNumber = '1005821'
```

If `Exists > 0`, the SKU is taken (even if soft-deleted).

---

## 7. Gotchas & Edge Cases

### 1. Query Encoding

The legacy code has special character encoding (`+`, `&`) but it's currently disabled. If you encounter issues with queries containing these characters, you may need to URL-encode them.

### 2. Transaction Isolation Level

Always prepend `SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED;` for consistency with the legacy implementation. This:
- Avoids locks
- Improves performance
- Allows reading uncommitted data (dirty reads)

### 3. SQL Syntax

Linnworks uses **SQL Server** syntax:
- Use square brackets for identifiers: `[StockItem]`
- Use `TOP` not `LIMIT`: `SELECT TOP 10 * FROM StockItem`
- Use `GETDATE()` not `NOW()`
- Boolean values: `'True'`/`'False'` or `0`/`1`

### 4. Retry Logic

The legacy code implements retry on these HTTP status codes:
- **401** (Unauthorized) - refreshes token and server URL
- **404** (Not Found) - refreshes server URL
- **429** (Too Many Requests) - rate limiting
- **503** (Server Unavailable)
- **409** (Conflict)

Max 10 retry attempts with exponential backoff (0.5x multiplier).

**File:** `legacy/src/AlzMvc/Core/Factory/Guzzle/Middleware/GuzzleRetryLwRefreshToken.php`

### 5. Large Result Sets

No explicit pagination in the legacy queries. For large datasets, use `TOP`:
```sql
SELECT TOP 1000 * FROM StockItem WHERE bLogicalDelete = 0
```

---

## 8. File Reference Index

| File | Purpose |
|------|---------|
| `legacy/src/Api/Linn2/src/Endpoint/Query.php` | Main Query endpoint class |
| `legacy/src/Api/Linn2/src/Model/LwQuery.php` | Query wrapper with isolation level |
| `legacy/src/Api/Linn2/src/Model/QueryResult.php` | Response model |
| `legacy/src/Api/Linn2/src/Http/RestClient.php` | HTTP client |
| `legacy/src/Api/Linn2/src/LinnApiClient.php` | API client factory |
| `legacy/src/AlzMvc/Service/Linnworks/Query/QueryLinnService.php` | Alternative query service |
| `legacy/src/AlzMvc/Service/Linnworks/Contract/ICanQuery.php` | Query interface |
| `legacy/src/AlzMvc/Core/Factory/Guzzle/Middleware/GuzzleRetryLwRefreshToken.php` | Retry middleware |
| `legacy/src/Linnworks/Sql/StockItem.php` | Example SQL queries |

---

## Summary

To query soft-deleted items in Linnworks:

1. **POST** to `{server}/api/Dashboards/ExecuteCustomScriptQuery`
2. **Request body**: `request={"Script":"SET TRANSACTION ISOLATION LEVEL READ UNCOMMITTED; YOUR_SQL_HERE"}`
3. **Headers**: `Authorization: {token}` (raw, no Bearer), `Content-Type: application/x-www-form-urlencoded`
4. **Query** must NOT filter by `bLogicalDelete` to include soft-deleted items

Example query for your use case:
```sql
SELECT pkStockItemID, ItemNumber, bLogicalDelete
FROM StockItem
WHERE ItemNumber = '1005821'
```
