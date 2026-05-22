# ADR-0003: Bing conversion uploads use `microsoft/msads` REST SDK with existing session manager bridge

**Context.** Bing Ads offline conversion tracking needs REST API calls. The codebase already has `microsoft/bingads` (SOAP SDK) for campaign metrics reporting, plus a `BingAdsSessionManager` that handles OAuth token refresh with Redis caching and thundering-herd locking. Microsoft deprecated the SOAP SDK (EOL January 2027) and recommends the REST SDK `microsoft/msads` for new integrations.

**Decision.** Install `microsoft/msads` alongside the existing SOAP SDK. The new Bing conversion transport uses the REST SDK's typed model objects (`OfflineConversion`, `ApplyOfflineConversionsRequest`) and API client. Auth is bridged: `BingAdsSessionManager` remains the token lifecycle owner (Redis cache, lock, refresh) and populates the SDK's `OAuthTokens` object before each call.

**Why.** Three options were considered:

1. **Direct HTTP (Laravel `Http` client)**: Simplest for a single endpoint, but building new code outside the SDK means a second migration effort when SOAP reporting moves to REST. Conversion uploads become the throwaway prototype instead of the beachhead.
2. **REST SDK with its own auth**: Duplicates token management — the SDK doesn't auto-refresh tokens, so we'd rebuild the Redis-cached, lock-protected refresh logic that `BingAdsSessionManager` already provides.
3. **REST SDK bridged to existing session manager** (chosen): Conversion work becomes the first REST SDK consumer, establishing patterns for the SOAP→REST migration. The session manager's production-tested caching and locking are reused rather than duplicated. When campaign metrics reporting migrates to REST, it joins the same SDK infrastructure and the `bing-ads-rest` circuit breaker key.
