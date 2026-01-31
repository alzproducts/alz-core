# Plan: Mixpanel API Log Decorator

## Overview

Implement a logging decorator for the Mixpanel HTTP transport following the established pattern from ShopWired and Linnworks.

## Pattern Summary

```
MixpanelTransportInterface (new)
        ↑
        ├── MixpanelHttpTransport (existing, add implements)
        └── LoggingMixpanelTransport (new decorator)
```

**Key difference from existing transports**: Mixpanel's `request()` method receives full URLs, not endpoint paths. The decorator extracts the path for logging.

---

## Files to Create

### 1. `app/Infrastructure/Mixpanel/Contracts/MixpanelTransportInterface.php`

Extract interface from `MixpanelHttpTransport::request()`:
- Single method: `request(string $method, string $url, ?string $body, ?string $contentType, bool $retry): Response`
- Copy all `@throws` annotations

### 2. `app/Infrastructure/Mixpanel/Enums/MixpanelLogLevel.php`

```php
enum MixpanelLogLevel: string
{
    case Info = 'info';    // Log endpoint, status, duration
    case Debug = 'debug';  // Also log request/response bodies
}
```

### 3. `app/Infrastructure/Mixpanel/LoggingMixpanelTransport.php`

Decorator implementing `MixpanelTransportInterface`:
- Constants: `SERVICE_NAME = 'Mixpanel'`, `MAX_BODY_LENGTH = 1000`
- Constructor: `$inner` (MixpanelTransportInterface), `$logLevel` (MixpanelLogLevel)
- `request()`: logs before/after, measures duration, delegates to inner
- Private helpers: `extractEndpoint()`, `logRequest()`, `logResponse()`, `truncate()`

**URL-to-endpoint extraction**:
```php
// https://api-eu.mixpanel.com/import?project_id=123 → /import?project_id=123
$path = parse_url($url, PHP_URL_PATH) ?? '/';
$query = parse_url($url, PHP_URL_QUERY);
return $query ? "{$path}?{$query}" : $path;
```

---

## Files to Modify

### 4. `app/Infrastructure/Mixpanel/MixpanelHttpTransport.php`

Add interface implementation:
```php
final readonly class MixpanelHttpTransport implements MixpanelTransportInterface
```

### 5. `app/Infrastructure/Mixpanel/MixpanelClient.php`

Update constructor type hint (line 46):
```php
public function __construct(
    private MixpanelTransportInterface $transport,  // was: MixpanelHttpTransport
    private MixpanelConfig $config,
) {}
```

### 6. `app/Infrastructure/Mixpanel/MixpanelClientFactory.php`

Add conditional decorator wrapping (no static singleton — SP already handles caching):
```php
public static function create(): MixpanelClientInterface
{
    $config = self::createConfig();
    $transport = self::createTransport($config);
    return new MixpanelClient($transport, $config);
}

private static function createTransport(MixpanelConfig $config): MixpanelTransportInterface
{
    $baseTransport = new MixpanelHttpTransport($config);

    $logLevel = config('mixpanel.log_level');
    if (!is_string($logLevel) || $logLevel === '') {
        return $baseTransport;  // No logging overhead
    }

    $parsed = MixpanelLogLevel::tryFrom($logLevel);
    if ($parsed === null) {
        throw new InvalidConfigurationException(
            'MIXPANEL_LOG_LEVEL',
            "Invalid value '{$logLevel}'. Must be 'info' or 'debug'."
        );
    }

    return new LoggingMixpanelTransport($baseTransport, $parsed);
}
```

**Note**: Unlike ShopWired/Linnworks, we don't add a static singleton here — the service provider already registers `MixpanelClientInterface` as a singleton, so the factory is only called once.

### 7. `config/mixpanel.php`

Add log level config (after HTTP Transport Settings):
```php
/*
|--------------------------------------------------------------------------
| API Logging
|--------------------------------------------------------------------------
| Control API request/response logging for debugging purposes.
| - null/empty: No logging (production)
| - 'info': Log endpoint, status, duration
| - 'debug': Also log request/response bodies (truncated)
*/
'log_level' => env('MIXPANEL_LOG_LEVEL'),
```

---

## Tests to Create

### 8. `tests/Unit/Infrastructure/Mixpanel/LoggingMixpanelTransportTest.php`

Test cases:
- Delegates to inner transport correctly
- Logs request at Info level (method, endpoint)
- Logs request at Debug level (includes body)
- Logs response (status, duration_ms)
- Logs response body at Debug level
- Extracts endpoint from various URL formats
- Truncates long bodies at 1000 chars
- Handles null body gracefully
- Propagates exceptions from inner transport

---

## Implementation Order

1. Create interface (`MixpanelTransportInterface`)
2. Create enum (`MixpanelLogLevel`)
3. Update `MixpanelHttpTransport` to implement interface
4. Create decorator (`LoggingMixpanelTransport`)
5. Update `MixpanelClient` constructor type hint
6. Update `MixpanelClientFactory` with conditional wrapping
7. Add config key
8. Write tests

---

## Verification

1. **Unit tests**: `make test tests/Unit/Infrastructure/Mixpanel/LoggingMixpanelTransportTest.php`
2. **Existing tests still pass**: `make test-quick` (ensure no regressions)
3. **Manual test** (optional):
   - Set `MIXPANEL_LOG_LEVEL=debug` in `.env`
   - Trigger a Mixpanel sync job
   - Check Laravel logs for "Mixpanel API request/response" entries
4. **Linting**: `make lint` (runs automatically via stop hooks)

---

## Critical Files Reference

| Purpose | Path |
|---------|------|
| Pattern reference | `app/Infrastructure/Shopwired/LoggingShopwiredTransport.php` |
| Pattern reference | `app/Infrastructure/Linnworks/LoggingLinnworksTransport.php` |
| Base transport | `app/Infrastructure/Mixpanel/MixpanelHttpTransport.php` |
| Client (update) | `app/Infrastructure/Mixpanel/MixpanelClient.php` |
| Factory (update) | `app/Infrastructure/Mixpanel/MixpanelClientFactory.php` |
| Config (update) | `config/mixpanel.php` |
