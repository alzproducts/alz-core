# Mixpanel API Client - Architecture Notes

## Template Pattern: Config → Transport → Client → Factory

This module follows the standardized API client template pattern:

```
MixpanelConfig (VO)
    ↓
MixpanelHttpTransport (HTTP layer)
    ↓
MixpanelClient (business logic)
    ↓
MixpanelClientFactory (wiring)
```

## Layer Responsibilities

### MixpanelConfig
- Immutable value object with fail-fast validation
- Validates: non-empty strings, timeout (1-300s), retry (0-10), delay (0-5000ms)
- Two API URLs: `MAIN_API_URL` (auth), `DEFAULT_DATA_API_URL` (data ingestion)

### MixpanelHttpTransport
- Single `request()` method handles all HTTP verbs
- Applies Basic Auth, timeout, and retry logic
- Catches `RequestException` AND `ConnectionException`
- Translates to `ExternalServiceUnavailableException`
- Parses `Retry-After` header for rate limits (429)

### MixpanelClient
- Pure business logic, no HTTP concerns
- Methods: `verifyConnectivity()`, `importCampaigns()`, `replaceCampaignLookupTable()`
- Transforms Domain objects to API format using DTOs
- Delegates all HTTP to transport layer

### MixpanelClientFactory
- Boot-time configuration validation
- Wires Config → Transport → Client chain
- Throws `RuntimeException` for missing env vars

## Dual API URLs

Mixpanel uses two different base URLs:

| Purpose        | URL                        | Used By                                             |
|----------------|----------------------------|-----------------------------------------------------|
| Auth/Account   | `https://mixpanel.com`     | `verifyConnectivity()`                              |
| Data Ingestion | `https://api.mixpanel.com` | `importCampaigns()`, `replaceCampaignLookupTable()` |

## Configuration

Set in `.env`:
```
MIXPANEL_BASE_URL=https://api.mixpanel.com
MIXPANEL_PROJECT_ID=your-project-id
MIXPANEL_SERVICE_ACCOUNT_USERNAME=your-username
MIXPANEL_SERVICE_ACCOUNT_PASSWORD=your-password
MIXPANEL_UTM_CAMPAIGN_LOOKUP_TABLE_ID=your-table-id
MIXPANEL_TIMEOUT=30
MIXPANEL_RETRY_TIMES=3
MIXPANEL_RETRY_DELAY=100
```

## Adding New Endpoints

1. Add method to `MixpanelClient` that transforms domain objects
2. Call `$this->transport->request()` with appropriate params
3. Use `retry: false` for fail-fast operations (like auth checks)

```php
public function newEndpoint(array $data): void
{
    $payload = array_map(fn($item) => SomeDTO::from($item)->toFormat(), $data);

    $this->transport->request(
        method: 'POST',
        url: "{$this->config->dataApiBaseUrl}/new-endpoint",
        body: json_encode($payload),
        contentType: 'application/json',
    );
}
```
