# Two-tier cache abstractions: ResilientCache and LockableCache

The codebase needs two distinct cache abstractions because caching requirements fall into two categories that shouldn't be conflated:

1. **ResilientCache** (`ResilientCacheInterface`) — graceful degradation only. Read/write/delete failures are caught and logged; the application continues as if the cache missed. For performance-only caching where correctness doesn't depend on cache availability. Supports optional single-tag group invalidation.

2. **LockableCache** (`LockableCacheInterface`) — graceful degradation plus atomic locking. Adds thundering herd protection (only one process refreshes), double-check after lock acquisition, and stale-value fallback. For expensive or contention-prone operations (OAuth tokens, API sessions).

Both live behind Application-layer interfaces with Infrastructure implementations backed by Laravel's `CacheManager`. We chose `CacheManager` over PSR-16 (`SimpleCache\CacheInterface`) because PSR-16 doesn't support tags or Laravel's lock primitives — and since both implementations already live in Infrastructure, there's no layer-purity benefit to the PSR abstraction.

Tag support uses a single `?string $tag` parameter (not an array) to avoid the compound-namespace pitfall: Laravel creates a combined namespace from multiple tags, requiring the exact same tag set on every operation. A single tag keeps the contract simple and covers the primary use case (group invalidation by concern).
