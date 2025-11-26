# Plan: Add Shopwired API Client

## Overview

Add Shopwired API client following the established template pattern (Mixpanel/ReviewsIO).

## API Details

- **Base URL**: `https://api.ecommerceapi.uk/v1`
- **Auth**: HTTP Basic Auth (same as Mixpanel)
  - `SHOPWIRED_API_KEY` = Basic Auth username
  - `SHOPWIRED_API_SECRET` = Basic Auth password
- **Verify endpoint**: `GET /business` (returns 200 on success)
- **Env vars**: `SHOPWIRED_API_KEY`, `SHOPWIRED_API_SECRET`

## Files to Create

### 1. Interface (Application Layer)

`app/Application/Contracts/ShopwiredClientInterface.php`

```php
interface ShopwiredClientInterface
{
    /**
     * Verify API connectivity and authentication.
     * @throws ExternalServiceUnavailableException
     */
    public function verifyConnectivity(): void;
}
```

### 2. Config Value Object

`app/Infrastructure/Shopwired/ShopwiredConfig.php`

- Readonly class with fail-fast validation
- Properties: `apiKey`, `apiSecret`, `baseUrl`, `timeout`, `retryTimes`, `retryDelay`
- Constant: `DEFAULT_BASE_URL = 'https://api.ecommerceapi.uk/v1'`

### 3. HTTP Transport

`app/Infrastructure/Shopwired/ShopwiredHttpTransport.php`

- Uses `Http::withBasicAuth($apiKey, $apiSecret)` (same pattern as Mixpanel)
- Retry strategy via `ApiRetryStrategy::defaultRetry()`
- Exception translation to `ExternalServiceUnavailableException`

### 4. Client

`app/Infrastructure/Shopwired/ShopwiredClient.php`

- Implements `ShopwiredClientInterface`
- `verifyConnectivity()`: Calls `GET /business` endpoint with `retry: false`

### 5. Factory

`app/Infrastructure/Shopwired/ShopwiredClientFactory.php`

- Static `create()` method
- Reads config, validates, wires dependencies

### 6. Service Provider

`app/Providers/ShopwiredServiceProvider.php`

- Deferred provider binding interface to factory

### 7. Config File

`config/shopwired.php`

```php
return [
    'api_key' => env('SHOPWIRED_API_KEY'),
    'api_secret' => env('SHOPWIRED_API_SECRET'),
    'timeout' => 30,
    'retry_times' => 3,
    'retry_delay' => 100,
];
```

## Files to Modify

### 1. Register Provider

`bootstrap/providers.php` - Add `ShopwiredServiceProvider::class`

### 2. Update Verify Command

`app/Presentation/Console/Commands/VerifyApiConnectivityCommand.php`

- Add `'shopwired'` case in match expression
- Add `verifyShopwired()` private method (same pattern as others)
- Update `'all'` case to include shopwired

### 3. Environment Example

`.env.example` - Add placeholder entries:

```
SHOPWIRED_API_KEY=
SHOPWIRED_API_SECRET=
```

### 4. Deptrac Config

`deptrac.yaml` - **No changes needed.** Shopwired client uses only Laravel HTTP facade which is already whitelisted for Infrastructure layer.

## Implementation Order

1. Create config file (`config/shopwired.php`)
2. Update `.env.example` with placeholders
3. Create interface (`ShopwiredClientInterface`)
4. Create config value object (`ShopwiredConfig`)
5. Create HTTP transport (`ShopwiredHttpTransport`)
6. Create client (`ShopwiredClient`)
7. Create factory (`ShopwiredClientFactory`)
8. Create service provider (`ShopwiredServiceProvider`)
9. Register provider in `bootstrap/providers.php`
10. Update `VerifyApiConnectivityCommand`
11. Run linters (`make lint`)
12. Write tests

## Testing

Create `tests/Feature/Infrastructure/Api/ShopwiredClientTest.php`:

- Test successful connectivity verification
- Test HTTP Basic Auth headers sent correctly
- Test exception translation (4xx, 5xx, connection errors)
- Test retry behavior disabled for verify

## Reference Files

- `app/Infrastructure/Mixpanel/MixpanelHttpTransport.php` - HTTP Basic Auth pattern
- `app/Infrastructure/ReviewsIo/ReviewsIoClient.php` - verifyConnectivity pattern
- `app/Providers/ReviewsIoServiceProvider.php` - Deferred provider pattern
