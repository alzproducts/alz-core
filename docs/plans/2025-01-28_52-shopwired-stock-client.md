# ShopWired StockClient Implementation Plan

## Summary

Add `StockClientInterface` and `StockClient` to handle stock quantity updates via ShopWired's `/stock` endpoint. Key features:
- Accepts `list<ItemStockLevel>` domain objects (sku + quantity)
- Auto-batches requests (max 15 items per API call)
- Uses `Http::pool()` for concurrent batch execution
- Validates response `updated` count matches input count
- Throws `StockUpdateFailedException` on mismatch

---

## Files to Create/Modify

### Domain Layer

**1. `app/Domain/Inventory/ValueObjects/ItemStockLevel.php`** (NEW)
```php
final readonly class ItemStockLevel
{
    public function __construct(
        public string $sku,      // Assert::notEmpty()
        public int $quantity,    // Assert::greaterThanEq(0)
    ) {}
}
```
Template: `app/Domain/Product/ValueObjects/ProductRating.php`

**2. `app/Domain/Exceptions/StockUpdateFailedException.php`** (NEW)
```php
final class StockUpdateFailedException extends DomainException
{
    public function __construct(
        public readonly int $expected,
        public readonly int $actual,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("Stock update failed: {$reason}", previous: $previous);
    }
}
```
Template: `app/Domain/Exceptions/UnexpectedApiResultException.php`

---

### Application Layer

**3. `app/Application/Contracts/Shopwired/StockClientInterface.php`** (NEW)
```php
interface StockClientInterface
{
    /**
     * @param list<ItemStockLevel> $items
     * @throws StockUpdateFailedException When updated count doesn't match
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws AuthenticationExpiredException When credentials invalid
     */
    public function updateStockQuantity(array $items): void;
}
```
Template: `app/Application/Contracts/Shopwired/OrderClientInterface.php`

---

### Infrastructure Layer

**4. `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php`** (MODIFY)

Add `poolPost()` method for concurrent POST requests:
```php
/**
 * @param array<string, array{endpoint: string, data: array}> $requests
 * @return array<string, Response>
 */
public function poolPost(
    array $requests,
    bool $retry = true,
    RetryStrategy $strategy = RetryStrategy::Background,
): array
```

This method:
- Accepts keyed array of endpoint/data pairs
- Creates `Http::pool()` with auth, timeout, retry per request
- Returns keyed Response array (caller handles validation)

**5. `app/Infrastructure/Shopwired/Clients/StockClient.php`** (NEW)
```php
final readonly class StockClient implements StockClientInterface
{
    private const string ENDPOINT_STOCK = 'stock';
    private const int BATCH_SIZE = 15;

    public function __construct(private ShopwiredHttpTransport $transport) {}

    public function updateStockQuantity(array $items): void
    {
        if ($items === []) return;

        $batches = array_chunk($items, self::BATCH_SIZE);
        $requests = $this->buildPoolRequests($batches);
        $responses = $this->transport->poolPost($requests);
        $this->validateResponses($responses, count($items));
    }
}
```
Template: `app/Infrastructure/Shopwired/Clients/OrderClient.php`

**6. `app/Infrastructure/Shopwired/ShopwiredClientFactory.php`** (MODIFY)

Add factory method:
```php
public static function createStockClient(): StockClientInterface
{
    return new StockClient(self::getTransport());
}
```

---

## Implementation Sequence

1. **Domain** (no dependencies):
   - Create `ItemStockLevel` value object + tests
   - Create `StockUpdateFailedException` + tests

2. **Application**:
   - Create `StockClientInterface`

3. **Infrastructure**:
   - Add `poolPost()` to `ShopwiredHttpTransport` + tests
   - Create `StockClient` + tests
   - Add factory method

4. **Validation**:
   - `make lint` (Pint, PHPStan, PHPArkitect, Deptrac)
   - `make test`
   - Mutation testing on StockClient

---

## Key Design Decisions

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Concurrency | `Http::pool()` via new `poolPost()` | User preference; 7× faster for large batches |
| Exception | Single `StockUpdateFailedException` | Covers count mismatch + batch failures with context |
| Value object location | `Domain/Inventory/ValueObjects/` | New namespace for inventory concepts |
| Client location | `Infrastructure/Shopwired/Clients/` | Consistent with OrderClient |
| Transport modification | Add `poolPost()` method | Keeps auth/retry config centralized |

---

## Error Handling Strategy

| Scenario | Exception | Behavior |
|----------|-----------|----------|
| Empty input | None | Early return (void) |
| All batches succeed, count matches | None | Success |
| Count mismatch | `StockUpdateFailedException` | expected/actual in properties |
| HTTP batch failure | `StockUpdateFailedException` | reason describes which batches |
| Auth failure (401/403) | `AuthenticationExpiredException` | Transport handles |
| Rate limit (429) | `ExternalServiceUnavailableException` | Transport retry handles |
| Connection error | `ExternalServiceUnavailableException` | Transport handles |

---

## Critical Files to Read Before Implementation

1. `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php` - Add poolPost() here
2. `app/Infrastructure/Shopwired/Clients/OrderClient.php` - Client pattern template
3. `app/Application/Contracts/Shopwired/OrderClientInterface.php` - Interface template
4. `app/Domain/Product/ValueObjects/ProductRating.php` - Value object template
5. `app/Domain/Exceptions/UnexpectedApiResultException.php` - Exception template

---

## API Reference

**Endpoint**: `POST /stock`
**Request**: `[{"sku": "ABC123", "quantity": 10}, ...]`
**Response**: `{"updated": N}`
**Limit**: Max 15 items per request
**Docs**: https://shopwired.readme.io/reference/updatestock