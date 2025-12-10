# Bing Ads API Client

## Pattern
`Config → SessionManager → Transport → Client → Factory`

SessionManager needed (unlike Google Ads) because PHP SDK doesn't auto-refresh OAuth tokens.

## Key Differences from Google Ads

| Aspect | Google Ads | Bing Ads |
|--------|------------|----------|
| Protocol | gRPC | SOAP |
| Reports | Instant (GAQL) | Async (submit → poll → ZIP → CSV) |
| Token refresh | SDK handles | Manual via `BingAdsSessionManager` |
| Rate limit | Retry-After header | Fixed 60s (no header) |

## SOAP Error Codes

| Code | Meaning | Exception |
|------|---------|-----------|
| 105, 106 | Auth failure | `AuthenticationExpiredException` |
| 117 | Rate limit | `ExternalServiceUnavailableException(retryAfter: 60)` |

## PHP SDK Limitation

`microsoft/bingads` lacks helper classes (ReportingServiceManager, BulkServiceManager) available in .NET/Java/Python. Async reporting requires manual SOAP implementation.