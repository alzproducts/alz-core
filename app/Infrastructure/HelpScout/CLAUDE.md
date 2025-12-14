# HelpScout Infrastructure

HelpScout API integration for customer service dashboard widgets.

## Key Design Decisions

### Direct HTTP Instead of SDK Entity Hydration

The HelpScout PHP SDK drops fields during entity hydration (notably `snooze`). We use the SDK's OAuth2 authenticator for token management but bypass its entity hydration with direct HTTP calls parsed into Spatie DTOs.

### DTO → Domain Transformation

All Infrastructure DTOs in `Responses/` have a `toDomain()` method that returns the corresponding Domain value object. Clients pass these transformation closures to the response parser, keeping the mapping logic co-located with the DTO definition.

### Date Handling

Date strings are parsed in `toDomain()` methods. Malformed dates throw `InvalidApiResponseException` to signal an API contract violation rather than silently failing.

## Exception Translation

`HelpScoutHttpTransport` translates HTTP errors to Domain exceptions:
- **400** → `InvalidApiRequestException` (programming error)
- **401/403** → `AuthenticationExpiredException`
- **429** → `ExternalServiceUnavailableException` (respects Retry-After header)
- **5xx/Connection** → `ExternalServiceUnavailableException`

Spatie DTO validation failures → `InvalidApiResponseException` (API contract changed)

## Adding New Endpoints

1. Create Domain VO in `Domain/CustomerService/ValueObjects/`
2. Create Infrastructure DTO in `Responses/` with `toDomain()` method
3. Add interface method in `Application/Contracts/HelpScout/`
4. Implement in Client using parser's `parseEmbeddedCollectionToDomain()`
5. Register binding in service provider