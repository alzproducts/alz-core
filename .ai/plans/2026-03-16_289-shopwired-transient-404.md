# Fix: ShopWired 404s treated as transient + fetch inside error envelope

**Fixes:** Sentry ALZ-CORE-4P
**Branch:** `feature/ALZ-CORE-4P-shopwired-transient-404`

## Context

ShopWired webhook triggers `SyncShopwiredOrderJob`, which immediately fetches the order via GET API. ShopWired returns 404 due to eventual consistency lag — the webhook arrived before the GET API had the resource available. Currently:

1. The fetch call (`getOrderById`) sits outside the `executeSync()` error handling envelope, so exceptions bypass the structured retry/fail logic
2. ShopWired 404s throw `ResourceNotFoundException` (extends `PermanentApiFailure`), causing immediate job failure with no retry — even though the resource likely exists

Two changes needed: bring the fetch into the error envelope, and classify ShopWired 404s as transient.

---

## Part 1: `withErrorHandling()` — bring fetch into the error envelope

Replace `executeSync(object $entity, RepositoryWriteInterface $repo, ...)` with `withErrorHandling(LoggerInterface $logger, Closure $work)`. The children own all their work (fetch + save); the abstract class provides only the error handling algorithm.

### Files

**`app/Application/Jobs/Shopwired/AbstractSyncShopwiredEntityJob.php`**
- Rename `executeSync()` → `withErrorHandling(LoggerInterface $logger, \Closure $work)`
- Remove `$entity` and `$repo` parameters — the Closure encapsulates all work
- Inside try block: call `$work()` then log success
- Exception catches remain identical (`TransientApiFailure`, `PermanentApiFailure`, `Throwable`)
- Remove `@template TEntity` and `RepositoryWriteInterface` import (no longer needed)
- Signature: `protected function withErrorHandling(LoggerInterface $logger, \Closure $work): void`

**`app/Application/Jobs/Shopwired/SyncShopwiredOrderJob.php`**
```php
public function handle(
    OrderClientInterface $client,
    OrderRepositoryInterface $repo,
    LoggerInterface $logger,
): void {
    $this->withErrorHandling($logger, function () use ($client, $repo): void {
        $order = $client->getOrderById($this->entityId->value);
        $repo->save($order);
    });
}
```

**`app/Application/Jobs/Shopwired/SyncShopwiredProductJob.php`** — same pattern with `getProductById`

**`app/Application/Jobs/Shopwired/SyncShopwiredCustomerJob.php`** — same pattern with `getCustomerById`

---

## Part 2: New `ResourceNotAvailableException` — ShopWired 404s as transient

### New file

**`app/Domain/Exceptions/Api/ResourceNotAvailableException.php`**
```php
final class ResourceNotAvailableException extends TransientApiFailure
{
    public function __construct(
        string $serviceName,
        public readonly string $resourceType,
        public readonly int|string $resourceId,
        ?int $retryAfter = null,
        ?Throwable $previous = null,
    ) {
        parent::__construct(
            $serviceName,
            $retryAfter,
            "{$resourceType} with ID '{$resourceId}' not available in {$serviceName} — may not exist or may not be available yet",
            $previous,
        );
    }
}
```

### Transport changes

**`app/Infrastructure/Shopwired/ShopwiredHttpTransport.php`**
- `handleNotFound()` — change return type and body:
  - Return `ResourceNotAvailableException` instead of `ResourceNotFoundException`
  - Update log message: `"ShopWired returned 404 for {endpoint}, treating as transient (possible consistency lag)"`
  - Return `new ResourceNotAvailableException(self::SERVICE_NAME, $endpoint, 'unknown', retryAfter: 30)` — 30s default retry gives ShopWired time to propagate
- `handleRequestException()` — update return type union: replace `ResourceNotFoundException` with `ResourceNotAvailableException`
- `getResource()` — update catch block: catch `ResourceNotAvailableException` instead of `ResourceNotFoundException`, re-throw as `new ResourceNotAvailableException(self::SERVICE_NAME, $resourceType, $id, retryAfter: 30, previous: $e)` for proper context enrichment
- Update `@throws` docblocks on `get()`, `getResource()`, and any other public methods that currently declare `@throws ResourceNotFoundException`

**`app/Infrastructure/Shopwired/Contracts/ShopwiredTransportInterface.php`**
- Update `@throws` docblocks on `getResource()` and `get()`: `ResourceNotFoundException` → `ResourceNotAvailableException`

**`app/Infrastructure/Shopwired/LoggingShopwiredTransport.php`**
- Update `@throws` docblocks to match interface changes

### Client docblock updates

These clients declare `@throws ResourceNotFoundException` in docblocks. Update to `@throws ResourceNotAvailableException`:
- `app/Infrastructure/Shopwired/Clients/OrderClient.php` — `getOrderById()` and other methods
- `app/Infrastructure/Shopwired/Clients/ProductClient.php` — `getProductById()` and other methods
- `app/Infrastructure/Shopwired/Clients/CustomerClient.php` — `getCustomerById()` and other methods

**Note:** Only update `@throws` for methods that route through `ShopwiredHttpTransport`. Other ShopWired clients (`CategoryClient`, `FilterGroupClient`, `WebhookClient`, etc.) that also declare `@throws ResourceNotFoundException` need the same docblock update.

Corresponding contract interfaces in `app/Application/Contracts/Shopwired/` need their `@throws` updated too:
- `OrderClientInterface.php`
- `ProductClientInterface.php`
- `CustomerClientInterface.php`
- And any other interfaces whose methods declare `@throws ResourceNotFoundException` for ShopWired operations

### Test update

**`tests/Feature/Infrastructure/Api/ShopwiredClientTest.php`**
- Update 404 test (line 194-202): expect `ResourceNotAvailableException` instead of `ResourceNotFoundException`

---

## Part 3: What does NOT change

- `ResourceNotFoundException` class — untouched, still used by Linnworks, ReviewsIo, and other services
- Exception hierarchy — no changes to `PermanentApiFailure`, `TransientApiFailure`, `AbstractApiException`
- Other transports (Linnworks, ReviewsIo) — their 404 handling stays as `ResourceNotFoundException`
- `handleRequestException()` still routes 404 → `handleNotFound()` — just the return type changes

---

## Verification

1. `make fix` — auto-fix code style
2. `make lint` — PHPStan max should pass cleanly (no generic complexity, simple Closure type)
3. `make test` — existing tests pass, 404 test updated
4. Manual: check that `withErrorHandling` correctly catches `ResourceNotAvailableException` (which extends `TransientApiFailure`) and releases the job with `retryAfter`
