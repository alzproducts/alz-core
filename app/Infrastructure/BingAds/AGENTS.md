# Bing Ads API Client

## Dual Transport Architecture

Two SDK packages, two transport classes, one `SessionManager`:

| Concern | SDK Package | Transport | Protocol |
|---------|-------------|-----------|----------|
| Ad-spend reporting | `microsoft/bingads` | `BingAdsTransport` (SOAP) | SOAP |
| Offline conversions | `microsoft/msads` | `BingAdsConversionTransport` (REST) | REST |

Both transports bridge auth from `BingAdsSessionManager` (OAuth token refresh + Redis caching). See ADR-0003.

## Key Differences from Google Ads

| Aspect | Google Ads | Bing Ads |
|--------|------------|----------|
| Protocol | gRPC | SOAP + REST (dual) |
| Reports | Instant (GAQL) | Async (submit → poll → ZIP → CSV) |
| Token refresh | SDK handles | Manual via `BingAdsSessionManager` |
| Rate limit | Retry-After header | Fixed 60s (no header) |
| Circuit breaker | `google-ads` | `bing-ads-rest` (conversions) |

## SOAP Error Codes

| Code | Meaning | Exception |
|------|---------|-----------|
| 105, 106 | Auth failure | `AuthenticationExpiredException` |
| 117 | Rate limit | `ExternalServiceUnavailableException(retryAfter: 60)` |

## PHP SDK Limitation

`microsoft/bingads` lacks helper classes (ReportingServiceManager, BulkServiceManager) available in .NET/Java/Python. Async reporting requires manual SOAP implementation.

## Async Report Flow

```
Transport::getCampaignPerformanceReportCsv()
    ├─ submitReport()      → SOAP SubmitGenerateReport → ReportRequestId
    ├─ pollUntilComplete() → SOAP PollGenerateReport (loop) → Download URL
    ├─ downloadReport()    → HTTP GET → ZIP bytes
    └─ extractCsvFromZip() → ZipArchive → CSV string

BingAdsCsvTransformer::toCampaignMetrics(csv)
    → array<CampaignMetrics>
```

**Transport returns CSV string** (not parsed data) - keeps SDK/HTTP/ZIP concerns isolated.
**Client uses Transformer** to convert CSV → domain objects.

## CSV Format Notes

- CSV includes metadata header rows before column names
- Transformer finds header by looking for `CampaignId` column
- Date format: Expected `YYYY-MM-DD` (throws with actual format if different)
- Spend: Account currency (GBP), not micros