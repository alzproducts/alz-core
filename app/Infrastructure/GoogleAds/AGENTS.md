# Google Ads Infrastructure

## Pattern

`Config → Transport → Client → Factory`

- **Transport**: Wraps SDK, translates exceptions to domain exceptions
- **Client**: Pure business logic, constructs GAQL queries
- **Transformers**: `GoogleAdsRow` → domain value objects

## SDK Enum Values

Google Ads SDK returns integers, not strings:

| Status | Value |
|--------|-------|
| UNSPECIFIED | 0 |
| UNKNOWN | 1 |
| ENABLED | 2 |
| PAUSED | 3 |
| REMOVED | 4 |

## Exception Translation

| SDK Exception | Domain Exception |
|---------------|------------------|
| `ApiException` (RESOURCE_EXHAUSTED) | `ExternalServiceUnavailableException` with retry-after |
| `ApiException` (other) | `ExternalServiceUnavailableException` |
| `ValidationException` | `InvalidApiRequestException` |

## Key Differences from HTTP Clients

- REST transport via `google/gax` auto-detection (`ext-grpc` not installed → falls back from gRPC to REST). Installing `ext-grpc` would auto-enable gRPC with no code changes.
- SDK handles OAuth2 refresh, retry, connection pooling
- GAQL queries, not REST endpoints
- `PagedListResponse` iterables, not JSON