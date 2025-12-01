# Linnworks API Integration Plan

## Overview

Design a Linnworks API integration following existing ShopWired patterns while adapting for Linnworks' unique session-based authentication flow.

**Key Differences from ShopWired:**
- Session-based auth (not Basic Auth) - requires auth endpoint call first
- Dynamic server URL returned per session (region-aware routing)
- Session cached in Redis with TTL management
- Automatic 401 retry with transparent re-authentication

---

## Architecture Decision

**Transport-Internal Session Management** (matches ShopWired pattern)

The transport manages session lifecycle internally:
- Lazy-authenticates on first request
- Caches session in Redis with TTL
- Intercepts 401, re-auths, retries transparently (once)
- Uses atomic locks to prevent thundering herd

**Rationale:** Clients remain simple (no auth awareness), matching how ShopWired clients don't know about Basic Auth.

---

## File Structure

```
app/Infrastructure/Linnworks/
├── LinnworksConfig.php              # Immutable config VO (credentials + timeout)
├── LinnworksSession.php             # Session VO (token, serverUrl, expiresAt)
├── LinnworksSessionManager.php      # Session lifecycle (auth, cache, refresh)
├── LinnworksHttpTransport.php       # HTTP transport (uses SessionManager)
├── LinnworksClientFactory.php       # Static factory with lazy singleton
├── LinnworksResponseParserTrait.php # Spatie DTO parsing (copy from ShopWired)
├── RetryStrategy.php                # Background/Urgent enum (copy from ShopWired)
├── Clients/
│   └── InventoryClient.php          # Implements InventoryClientInterface
├── Responses/
│   └── StockItem.php                # Spatie DTO (all Linnworks fields)
└── Support/
    └── PascalCaseMapper.php         # Spatie mapper: PascalCase → camelCase

config/linnworks.php                 # Laravel config file

app/Application/Contracts/Linnworks/
└── InventoryClientInterface.php     # getInventoryItemById(string $stockItemId)

app/Domain/Inventory/
└── StockItem.php                    # Vendor-agnostic domain value object
```

---

## Component Design

### 1. LinnworksConfig (Value Object)

```php
final readonly class LinnworksConfig
{
    public const string AUTH_URL = 'https://api.linnworks.net/api/Auth/AuthorizeByApplication';

    public function __construct(
        public string $applicationId,
        public string $applicationSecret,
        public string $installationToken,  // User's access token
        public int $timeout = 30,
        public int $cacheTtlBuffer = 300,  // Subtract from TTL as safety margin
    ) {
        // Fail-fast validation: RuntimeException if empty
    }
}
```

### 2. LinnworksSession (Value Object)

```php
final readonly class LinnworksSession
{
    public function __construct(
        public string $token,
        public string $serverUrl,
        public DateTimeImmutable $expiresAt,
    ) {}

    public function isExpired(): bool
    {
        return new DateTimeImmutable() >= $this->expiresAt;  // Always fresh (Octane-safe)
    }

    public static function fromAuthResponse(array $response, int $ttlBuffer): self
    {
        // Creates session with TTL buffer applied
    }
}
```

### 3. LinnworksSessionManager (Session Lifecycle)

Handles all session concerns - keeps transport focused on HTTP:

```php
final class LinnworksSessionManager
{
    private const string CACHE_KEY = 'linnworks:session';
    private const string LOCK_KEY = 'linnworks:session:lock';

    public function __construct(
        private readonly LinnworksConfig $config,
        private readonly CacheRepository $cache,
    ) {}

    /**
     * Get valid session (cache-first, authenticates if needed).
     */
    public function getSession(): LinnworksSession
    {
        $cached = $this->cache->get(self::CACHE_KEY);
        if ($cached instanceof LinnworksSession && !$cached->isExpired()) {
            return $cached;
        }

        return $this->authenticateWithLock();
    }

    /**
     * Invalidate cached session (called by transport on 401).
     */
    public function invalidate(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Authenticate with atomic lock to prevent thundering herd.
     */
    private function authenticateWithLock(): LinnworksSession
    {
        return $this->cache->lock(self::LOCK_KEY, 30)->block(10, function () {
            // Double-check after acquiring lock
            $cached = $this->cache->get(self::CACHE_KEY);
            if ($cached instanceof LinnworksSession && !$cached->isExpired()) {
                return $cached;
            }

            return $this->authenticate();
        });
    }

    /**
     * Call Linnworks auth endpoint.
     */
    private function authenticate(): LinnworksSession
    {
        $response = Http::timeout($this->config->timeout)
            ->post(LinnworksConfig::AUTH_URL, [
                'ApplicationId' => $this->config->applicationId,
                'ApplicationSecret' => $this->config->applicationSecret,
                'Token' => $this->config->installationToken,
            ])
            ->throw();

        $session = LinnworksSession::fromAuthResponse(
            $response->json(),
            $this->config->cacheTtlBuffer,
        );

        $ttl = $session->expiresAt->getTimestamp() - time();
        $this->cache->put(self::CACHE_KEY, $session, max(1, $ttl));

        return $session;
    }
}
```

**Responsibilities:**
- Cache lookup and storage (Redis)
- Atomic locks for concurrent auth prevention
- Auth endpoint call
- TTL management with buffer

### 4. LinnworksHttpTransport (HTTP Only)

Now focused purely on HTTP concerns - delegates session to SessionManager:

```php
final readonly class LinnworksHttpTransport
{
    private const string SERVICE_NAME = 'Linnworks';

    public function __construct(
        private LinnworksConfig $config,
        private LinnworksSessionManager $sessionManager,
    ) {}

    public function get(string $endpoint, array $query = [], ...): Response
    {
        return $this->executeWithAuthRetry(
            fn(LinnworksSession $session) => $this->doGet($session, $endpoint, $query, ...)
        );
    }

    /**
     * Execute with automatic 401 retry (once).
     */
    private function executeWithAuthRetry(Closure $request): Response
    {
        $session = $this->sessionManager->getSession();

        try {
            return $request($session);
        } catch (RequestException $e) {
            if ($e->response->status() === 401) {
                // Invalidate and retry once
                $this->sessionManager->invalidate();
                $session = $this->sessionManager->getSession();
                return $request($session);
            }
            throw $this->handleRequestException($e, '');
        }
    }

    private function doGet(LinnworksSession $session, string $endpoint, array $query, ...): Response
    {
        return Http::baseUrl($session->serverUrl)  // Dynamic from session
            ->withToken($session->token)           // Bearer auth
            ->timeout($this->config->timeout)
            ->get($endpoint, $query)
            ->throw();
    }

    // Exception handlers (same as ShopWired - 400, 401, 404, 429, 5xx)
}
```

**Key responsibilities:**
1. HTTP request execution with dynamic base URL from session
2. 401 retry once (delegates invalidation to SessionManager)
3. Exception translation (same as ShopWired)

**Request Flow:**
```
get()/post()
    → executeWithAuth()
        → ensureSession() [memory → cache → auth with lock]
        → execute request to session.serverUrl
        → on 401 (first time): clearSession() → ensureSession() → retry
        → on 401 (second time): throw AuthenticationExpiredException
        → translate other errors to Domain exceptions
```

**Cache Strategy:**
- Key: `linnworks:session`
- Lock key: `linnworks:session:lock`
- TTL: API's TTL minus `cacheTtlBuffer` (default 5 minutes)
- Lock duration: 30 seconds, block timeout: 10 seconds

### 4. LinnworksClientFactory

```php
final class LinnworksClientFactory
{
    private static ?LinnworksHttpTransport $transport = null;

    public static function createOrderClient(): OrderClientInterface
    {
        return new OrderClient(self::getTransport());
    }

    private static function getTransport(): LinnworksHttpTransport
    {
        return self::$transport ??= self::createTransport();
    }

    private static function createTransport(): LinnworksHttpTransport
    {
        // Read config, validate, inject Cache via app()
        return new LinnworksHttpTransport($config, app(CacheRepository::class));
    }

    public static function reset(): void { self::$transport = null; }
}
```

---

## Exception Handling

Same pattern as ShopWired - all SDK exceptions translated at boundary:

| HTTP Status | Domain Exception |
|-------------|------------------|
| 400 | `InvalidApiRequestException` |
| 401/403 | `AuthenticationExpiredException` |
| 404 | `ResourceNotFoundException` |
| 429 | `ExternalServiceUnavailableException` (with Retry-After) |
| 5xx | `ExternalServiceUnavailableException` |
| Connection | `ExternalServiceUnavailableException` |

**Auth endpoint failures:**
- 401 on auth → `AuthenticationExpiredException` (credentials invalid)
- 5xx on auth → `ExternalServiceUnavailableException` (transient)

**Cache failures:** Graceful degradation (log warning, authenticate fresh)

---

## Layer Placement Summary

| Component | Layer | Rationale |
|-----------|-------|-----------|
| `LinnworksSession` | Infrastructure | API-specific state, not business concept |
| `LinnworksConfig` | Infrastructure | Follows existing API config pattern |
| `LinnworksHttpTransport` | Infrastructure | HTTP + auth concerns |
| `OrderClientInterface` | Application | Business contract |
| Session caching | Infrastructure | Auth optimization (not business cache) |

---

## Config File

```php
// config/linnworks.php
return [
    'application_id' => env('LINNWORKS_APPLICATION_ID'),
    'application_secret' => env('LINNWORKS_APPLICATION_SECRET'),
    'installation_token' => env('LINNWORKS_INSTALLATION_TOKEN'),
    'timeout' => 30,
    'cache_ttl_buffer' => 300,  // 5 minutes before actual expiry
];
```

---

## Deptrac Addition

```yaml
layers:
  - name: Linnworks
    collectors:
      - type: directory
        regex: app/Infrastructure/Linnworks/.*

ruleset:
  Linnworks:
    - Domain
    - Infrastructure_Support
    - Laravel
    - SpatieData
    - Webmozart
```

---

## First Endpoint: GetInventoryItemById

### Endpoint Details
- **URL:** `GET /api/Inventory/GetInventoryItemById?id=<GUID>`
- **Rate Limit:** 250/minute
- **Response:** StockItem object (see AbstractStockItem.php for fields)

### GUID Handling
Linnworks uses .NET GUIDs (36 characters: `xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx`).
In PHP: `string` type. No Domain value object needed.

### Domain Strategy

**Linnworks spans multiple business domains** (Inventory, Fulfillment, Purchasing).
Domain objects go in appropriate namespaces, NOT `Domain/Linnworks/`:

- Inventory items → `Domain/Inventory/`
- Orders/fulfillment → `Domain/Fulfillment/` (future)
- Purchase orders → `Domain/Purchasing/` (future)

### Application Contract

```php
// app/Application/Contracts/Linnworks/InventoryClientInterface.php
namespace App\Application\Contracts\Linnworks;

use App\Domain\Inventory\StockItem;

interface InventoryClientInterface
{
    /**
     * @param string $stockItemId 36-character GUID
     *
     * @throws ResourceNotFoundException When item doesn't exist
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function getInventoryItemById(string $stockItemId): StockItem;
}
```

### Domain Value Object (Vendor-Agnostic)

```php
// app/Domain/Inventory/StockItem.php
namespace App\Domain\Inventory;

final readonly class StockItem
{
    public function __construct(
        public string $sku,                // ItemNumber in Linnworks
        public string $title,              // ItemTitle
        public string $description,        // ItemDescription
        public string $barcode,            // BarcodeNumber
        public int $quantity,              // Total stock
        public int $available,             // Available for sale
        public int $inOrder,               // Reserved for orders
        public int $due,                   // Expected incoming
        public int $minimumLevel,          // Reorder threshold
        public float $purchasePrice,
        public float $retailPrice,
        public float $taxRate,
        public ?float $weight,
        public float $height,
        public float $width,
        public float $depth,
        public string $categoryName,
        public ?DateTimeImmutable $createdAt,
        public bool $isComposite,          // IsCompositeParent
    ) {}
}
```

**Excluded Linnworks-specific fields** (kept in Infrastructure DTO only):
- `StockItemId`, `StockItemIntId` - Linnworks internal IDs
- `CategoryId`, `PackageGroupId`, `PostalServiceId` - Linnworks GUIDs
- `isBatchedStockType`, `InventoryTrackingType` - Linnworks internals
- `BatchNumberScanRequired`, `SerialNumberScanRequired` - warehouse config

### Infrastructure DTO (Spatie Data)

```php
// app/Infrastructure/Linnworks/Responses/StockItem.php
namespace App\Infrastructure\Linnworks\Responses;

use App\Domain\Inventory\StockItem as DomainStockItem;
use App\Infrastructure\Linnworks\Support\PascalCaseMapper;
use App\Infrastructure\Support\DomainConvertible;
use Spatie\LaravelData\Attributes\MapInputName;
use Spatie\LaravelData\Data;

#[MapInputName(PascalCaseMapper::class)]
final class StockItem extends Data implements DomainConvertible
{
    public function __construct(
        // All Linnworks fields (PascalCase mapped to camelCase)
        public string $stockItemId,
        public int $stockItemIntId,
        public string $itemNumber,
        public string $itemTitle,
        public string $itemDescription,
        public string $barcodeNumber,
        public int $quantity,
        public int $inOrder,
        public int $due,
        public int $available,
        public int $minimumLevel,
        public float $purchasePrice,
        public float $retailPrice,
        public float $taxRate,
        public ?float $weight,
        public float $height,
        public float $width,
        public float $depth,
        public string $categoryId,
        public string $categoryName,
        public ?string $creationDate,
        public ?bool $isCompositeParent,
        public bool $isBatchedStockType,
        public int $inventoryTrackingType,
        // ... other Linnworks-specific fields
    ) {}

    public function toDomain(): DomainStockItem
    {
        return new DomainStockItem(
            sku: $this->itemNumber,
            title: $this->itemTitle,
            description: $this->itemDescription,
            barcode: $this->barcodeNumber,
            quantity: $this->quantity,
            available: $this->available,
            inOrder: $this->inOrder,
            due: $this->due,
            minimumLevel: $this->minimumLevel,
            purchasePrice: $this->purchasePrice,
            retailPrice: $this->retailPrice,
            taxRate: $this->taxRate,
            weight: $this->weight,
            height: $this->height,
            width: $this->width,
            depth: $this->depth,
            categoryName: $this->categoryName,
            createdAt: $this->creationDate ? new DateTimeImmutable($this->creationDate) : null,
            isComposite: $this->isCompositeParent ?? false,
        );
    }
}
```

### PascalCaseMapper (Custom Spatie Mapper)

```php
// app/Infrastructure/Linnworks/Support/PascalCaseMapper.php
namespace App\Infrastructure\Linnworks\Support;

use Spatie\LaravelData\Mappers\NameMapper;

final class PascalCaseMapper implements NameMapper
{
    public function map(int|string $name): int|string
    {
        if (is_int($name)) {
            return $name;
        }
        // Convert camelCase property to PascalCase for input matching
        return ucfirst($name);
    }
}
```

### InventoryClient Implementation

```php
// app/Infrastructure/Linnworks/Clients/InventoryClient.php
final readonly class InventoryClient implements InventoryClientInterface
{
    use LinnworksResponseParserTrait;

    public function __construct(
        private LinnworksHttpTransport $transport,
    ) {}

    public function getInventoryItemById(string $stockItemId): DomainStockItem
    {
        $response = $this->transport->get(
            '/api/Inventory/GetInventoryItemById',
            ['id' => $stockItemId],
        );

        return self::parseSingleToDomain($response->json(), StockItem::class);
    }
}
```

---

## Implementation Sequence

### Phase 1: Foundation
1. `config/linnworks.php`
2. `LinnworksConfig` with tests
3. `LinnworksSession` with tests
4. `LinnworksSessionManager` with tests (auth, cache, locks)
5. `PascalCaseMapper` (Spatie name mapper)
6. `LinnworksHttpTransport` with tests (uses SessionManager)
7. `LinnworksResponseParserTrait` (copy from ShopWired)
8. `LinnworksClientFactory`
9. Update `deptrac.yaml`

### Phase 2: First Client
1. `Domain/Inventory/StockItem` - vendor-agnostic value object
2. `InventoryClientInterface` in Application/Contracts/Linnworks
3. `Infrastructure/Linnworks/Responses/StockItem` - Spatie DTO with all Linnworks fields
4. `InventoryClient` implementation
5. Wire up in `LinnworksClientFactory::createInventoryClient()`

### Phase 3: Validation
1. `make lint` (Pint + PHPStan + PHPArkitect + Deptrac)
2. `make test`

---

## Critical Files to Reference

1. `app/Infrastructure/Shopwired/ShopwiredHttpTransport.php` - Exception handling template
2. `app/Infrastructure/Shopwired/ShopwiredConfig.php` - Config VO pattern
3. `app/Infrastructure/Shopwired/ShopwiredClientFactory.php` - Factory pattern
4. `app/Infrastructure/Shopwired/RetryStrategy.php` - Copy for Linnworks
5. `app/Infrastructure/Support/ApiRetryStrategy.php` - Retry condition helper

---

## Key Design Decisions

1. **Session in transport** (not separate manager class) - keeps pattern consistent with ShopWired where transport owns all HTTP/auth concerns

2. **Static singleton transport** - same as ShopWired, acceptable because session state is in Redis not in static property

3. **Atomic locks for auth** - prevents thundering herd when multiple requests hit expired session simultaneously

4. **TTL buffer** - re-authenticate 5 minutes before actual expiry to avoid edge-case failures

5. **Single retry on 401** - transparent to callers, but prevents infinite loops
