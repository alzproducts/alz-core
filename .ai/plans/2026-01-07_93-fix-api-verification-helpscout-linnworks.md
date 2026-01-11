# Plan: Fix API Verification Issues (HelpScout + Linnworks)

## Issues Summary
1. **HelpScout 401 in production** - Auth failure with `invalid_token` error
2. **HelpScout missing from `verify:api`** - Can't test HelpScout connectivity
3. **Linnworks DI failure** - Incomplete refactor from commit `6b744b0`

---

## Fix 1: Linnworks DI Bug (Critical - Blocking)

### Root Cause
Commit `6b744b0` refactored `LinnworksClientFactory::getSessionManager()` to use container resolution for contextual `LockableCacheInterface` binding, but **never registered `LinnworksSessionManager` in the provider**:

```php
// Before (working):
return self::$sessionManager ??= new LinnworksSessionManager(
    self::getConfig(),
    \app(CacheManager::class),
);

// After (broken):
return self::$sessionManager ??= \app(LinnworksSessionManager::class);  // Not registered!
```

Tests pass because they mock at interface level (`$this->app->instance(...)`), hiding the broken DI chain.

### Fix (Follow BingAds Pattern)

**File**: `app/Providers/LinnworksServiceProvider.php`

Register `LinnworksSessionManager` exactly like BingAds does:

```php
use App\Infrastructure\Linnworks\LinnworksConfig;
use App\Infrastructure\Linnworks\LinnworksSessionManager;
use App\Application\Contracts\LockableCacheInterface;
use App\Domain\Exceptions\InvalidConfigurationException;
use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Facades\Config;

// In register(), add BEFORE client bindings:
$this->app->singleton(
    LinnworksSessionManager::class,
    static fn(Container $app): LinnworksSessionManager => new LinnworksSessionManager(
        self::createConfig(),
        $app->make(LockableCacheInterface::class),
    ),
);

// Add private static method (matching BingAds pattern):
private static function createConfig(): LinnworksConfig
{
    $applicationId = \config('linnworks.application_id');
    $applicationSecret = \config('linnworks.application_secret');
    $installationToken = \config('linnworks.installation_token');

    if (!\is_string($applicationId) || ($applicationId === '')) {
        throw new InvalidConfigurationException('LINNWORKS_APPLICATION_ID');
    }
    if (!\is_string($applicationSecret) || ($applicationSecret === '')) {
        throw new InvalidConfigurationException('LINNWORKS_APPLICATION_SECRET');
    }
    if (!\is_string($installationToken) || ($installationToken === '')) {
        throw new InvalidConfigurationException('LINNWORKS_INSTALLATION_TOKEN');
    }

    return new LinnworksConfig(
        applicationId: $applicationId,
        applicationSecret: $applicationSecret,
        installationToken: $installationToken,
        timeout: Config::integer('linnworks.timeout', 30),
        cacheTtlBuffer: Config::integer('linnworks.cache_ttl_buffer', 300),
    );
}

// In provides(), add:
LinnworksSessionManager::class,
```

**Then remove duplicate validation** from `LinnworksClientFactory::createConfig()` - it's now in the provider.

---

## Fix 2: Add HelpScout Verification

### 2.1 Create Interface
**File**: `app/Application/Contracts/HelpScout/ConnectivityClientInterface.php`

```php
<?php

declare(strict_types=1);

namespace App\Application\Contracts\HelpScout;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;

interface ConnectivityClientInterface
{
    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     */
    public function verifyConnectivity(): void;
}
```

### 2.2 Create Client
**File**: `app/Infrastructure/HelpScout/Clients/ConnectivityClient.php`

```php
<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Clients;

use App\Application\Contracts\HelpScout\ConnectivityClientInterface;
use App\Infrastructure\HelpScout\HelpScoutHttpTransport;

final readonly class ConnectivityClient implements ConnectivityClientInterface
{
    public function __construct(
        private HelpScoutHttpTransport $transport,
    ) {}

    public function verifyConnectivity(): void
    {
        $this->transport->get('/mailboxes');
    }
}
```

### 2.3 Add Factory Method
**File**: `app/Infrastructure/HelpScout/HelpScoutClientFactory.php`

```php
use App\Application\Contracts\HelpScout\ConnectivityClientInterface;
use App\Infrastructure\HelpScout\Clients\ConnectivityClient;

public static function createConnectivityClient(): ConnectivityClientInterface
{
    return new ConnectivityClient(self::getTransport());
}
```

### 2.4 Register Binding
**File**: `app/Providers/HelpScoutServiceProvider.php`

```php
use App\Application\Contracts\HelpScout\ConnectivityClientInterface;

// In register():
$this->app->singleton(
    ConnectivityClientInterface::class,
    static fn(): ConnectivityClientInterface => HelpScoutClientFactory::createConnectivityClient(),
);

// In provides():
ConnectivityClientInterface::class,
```

### 2.5 Update Verify Command
**File**: `app/Presentation/Console/Commands/VerifyApiConnectivityCommand.php`

```php
use App\Application\Contracts\HelpScout\ConnectivityClientInterface as HelpScoutConnectivityClient;

// Update signature - add 'helpscout'
{client : The API client to verify (reviewsio, mixpanel, googleads, shopwired, linnworks, bingads, helpscout, all)}

// Add to match expression (after 'bingads')
'helpscout' => ['helpscout' => $this->verifyHelpScout()],

// Add to 'all' array (after 'linnworks')
'helpscout' => $this->verifyHelpScout(),

// Add method
private function verifyHelpScout(): bool
{
    $this->info('Verifying HelpScout...');

    try {
        $client = \app(HelpScoutConnectivityClient::class);
        $client->verifyConnectivity();

        $this->line('  Authentication: OK');
        $this->line('  API Response: Valid');

        return true;
    } catch (AuthenticationExpiredException $e) {
        $this->error('  Authorization Failed: ' . $e->getMessage());
        $this->line('  Check: HELPSCOUT_APP_ID and HELPSCOUT_APP_SECRET in .env');

        return false;
    } catch (Throwable $e) { // @ignoreException - connectivity test
        $this->error('  Failed: ' . $e->getMessage());
        $this->line('  Check: HELPSCOUT_APP_ID and HELPSCOUT_APP_SECRET in .env');

        return false;
    }
}
```

### 2.6 Add Tests

**Feature tests**: `tests/Feature/Presentation/Console/VerifyApiConnectivityCommandTest.php`
- Add mock setup for `HelpScoutConnectivityClient`
- `it_verifies_helpscout_successfully`
- `it_reports_helpscout_failure_with_exception_message`
- Update `all` test cases

**Unit test**: `tests/Unit/Infrastructure/HelpScout/Clients/ConnectivityClientTest.php`

---

## Fix 3: Diagnose HelpScout Production Auth

After Fixes 1 & 2:
1. Run `php artisan verify:api helpscout` locally
2. If local passes -> compare prod env vars
3. If local fails -> regenerate credentials in HelpScout admin

---

## Execution Order

1. Fix Linnworks DI -> `app/Providers/LinnworksServiceProvider.php`
2. Simplify factory -> `app/Infrastructure/Linnworks/LinnworksClientFactory.php`
3. Create HelpScout interface -> `app/Application/Contracts/HelpScout/ConnectivityClientInterface.php`
4. Create HelpScout client -> `app/Infrastructure/HelpScout/Clients/ConnectivityClient.php`
5. Add factory method -> `app/Infrastructure/HelpScout/HelpScoutClientFactory.php`
6. Register binding -> `app/Providers/HelpScoutServiceProvider.php`
7. Update verify command -> `app/Presentation/Console/Commands/VerifyApiConnectivityCommand.php`
8. Add unit test -> `tests/Unit/Infrastructure/HelpScout/Clients/ConnectivityClientTest.php`
9. Add feature tests -> `tests/Feature/Presentation/Console/VerifyApiConnectivityCommandTest.php`
10. Run `make test` and `make lint`
11. Test locally: `php artisan verify:api all`

---

## Files Summary

| File | Action |
|------|--------|
| `app/Providers/LinnworksServiceProvider.php` | Add `LinnworksSessionManager` binding + `createConfig()` |
| `app/Infrastructure/Linnworks/LinnworksClientFactory.php` | Remove duplicate `createConfig()` validation |
| `app/Application/Contracts/HelpScout/ConnectivityClientInterface.php` | **Create** |
| `app/Infrastructure/HelpScout/Clients/ConnectivityClient.php` | **Create** |
| `app/Infrastructure/HelpScout/HelpScoutClientFactory.php` | Add `createConnectivityClient()` |
| `app/Providers/HelpScoutServiceProvider.php` | Add binding + provides |
| `app/Presentation/Console/Commands/VerifyApiConnectivityCommand.php` | Add HelpScout case |
| `tests/Unit/Infrastructure/HelpScout/Clients/ConnectivityClientTest.php` | **Create** |
| `tests/Feature/Presentation/Console/VerifyApiConnectivityCommandTest.php` | Add HelpScout tests |
