# Google Ads API Client - Architecture Notes

## Template Pattern: Config → Transport → Client → Factory

This module follows the standardized API client template pattern:

```
GoogleAdsConfig (VO)
    ↓
GoogleAdsTransport (SDK wrapper)
    ↓
GoogleAdsClient (business logic)
    ↓
GoogleAdsClientFactory (wiring)
```

## Layer Responsibilities

### GoogleAdsConfig
- Immutable value object with fail-fast validation
- Validates: non-empty strings for all OAuth2 and API credentials
- Optional `loginCustomerId` for MCC (manager account) delegated access

### GoogleAdsTransport
- Wraps Google Ads SDK client (`SdkGoogleAdsClient`)
- Single `search()` method executes GAQL queries
- Translates SDK exceptions to domain exceptions:
  - `ApiException` (RESOURCE_EXHAUSTED) → `ExternalServiceUnavailableException` with retry-after
  - `ApiException` (other) → `ExternalServiceUnavailableException`
  - `ValidationException` → `InvalidApiRequestException` (programming error)
- Logs before translating (SDK details needed for debugging)

### GoogleAdsClient
- Pure business logic, no SDK concerns
- Methods: `getDailyCampaignMetrics()`, `getCampaigns()`
- Constructs GAQL queries and transforms responses
- Delegates SDK interaction to transport layer

### Row Transformers
- `GoogleAdsRowTransformer`: Transforms `GoogleAdsRow` → `CampaignMetrics` VO
- `CampaignRowTransformer`: Transforms `GoogleAdsRow` → `Campaign` VO
- Static methods, pure transformations
- Throws `InvalidGoogleAdsResponseException` for null/invalid data

### GoogleAdsClientFactory
- Boot-time configuration validation
- Wires Config → SDK Client → Transport → Client chain
- Throws `RuntimeException` for missing env vars

## SDK Enum Values

Google Ads SDK uses integer enum values (not strings):

| Status      | Enum Value |
|-------------|------------|
| UNSPECIFIED | 0          |
| UNKNOWN     | 1          |
| ENABLED     | 2          |
| PAUSED      | 3          |
| REMOVED     | 4          |

## Configuration

Set in `.env`:
```
GOOGLE_ADS_CLIENT_ID=your-client-id
GOOGLE_ADS_CLIENT_SECRET=your-client-secret
GOOGLE_ADS_REFRESH_TOKEN=your-refresh-token
GOOGLE_ADS_DEVELOPER_TOKEN=your-developer-token
GOOGLE_ADS_CUSTOMER_ID=1234567890
GOOGLE_ADS_LOGIN_CUSTOMER_ID=0987654321  # Optional, for MCC access
```

## Adding New Endpoints

1. Add method to `GoogleAdsClient` that constructs GAQL query
2. Call `$this->transport->search($query)`
3. Transform response rows using a transformer class

```php
public function getAdGroups(): array
{
    $query = <<<GAQL
        SELECT ad_group.id, ad_group.name, ad_group.status
        FROM ad_group
        WHERE ad_group.status != 'REMOVED'
        GAQL;

    $response = $this->transport->search($query);

    $adGroups = [];
    foreach ($response->iterateAllElements() as $row) {
        $adGroups[] = AdGroupRowTransformer::toAdGroup($row);
    }

    return $adGroups;
}
```

## Key Differences from HTTP-based Clients

- Uses gRPC via Google Ads SDK (not HTTP)
- SDK handles OAuth2 token refresh internally
- SDK handles retry logic, connection pooling, TLS
- Queries use GAQL (Google Ads Query Language), not REST endpoints
- Responses are `PagedListResponse` iterables, not JSON
