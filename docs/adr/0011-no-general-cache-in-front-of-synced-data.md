# No general cache in front of synced data

## Status

Accepted; amended (2026-10-31)

Most tables have their source of truth in a third-party system (ShopWired, Linnworks, HelpScout, ad platforms). Scheduled syncs and webhooks keep PostgreSQL holding a local copy of every remote system, which already provides the core benefit of a cache: fast local reads of remote data. Octane's persistent workers cover response time. A Redis read-through cache was started and stopped.

No general-purpose cache layer sits in front of synced data. Caching is applied only where an external contract demands it: OAuth session tokens (Bing Ads, Linnworks), HelpScout read responses (SDK/API constraints), and transient alert throttling. Those uses go through the abstractions recorded in [ADR 0005](0005-two-tier-cache-abstractions.md) and [ADR 0006](0006-transient-log-throttle.md).

There is no cache-invalidation or consistency surface on synced entities. Public endpoints are unaffected because they record submitted data or compute per-visit values and read nothing cached. Revisit if query performance on synced tables degrades or a read-heavy public feature is added.

## Amended (2026-10-31)

The rule stands with one exception: a cache over synced data is allowed only when its validity is recomputed from live data in the reading request's own snapshot — the reader hashes each entry's inputs in the transaction that reads them and serves the stored entry only on a hash match. No writer invalidates such a cache, so it adds no invalidation surface; caches invalidated by writers, a TTL or a scheduled refresh stay excluded.

The one such cache is the `GET /api/products` fragment cache (`catalog.product_list_fragments`, COR-258). The hourly `catalog:check-list-fragment-drift` command rebuilds a sample, reports and deletes any fragment whose body differs under a matching hash, and deletes fragments of deleted products.
