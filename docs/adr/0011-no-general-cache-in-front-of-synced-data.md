# No general cache in front of synced data

Accepted; amended (2026-10-31). Syncs and webhooks already keep a local PostgreSQL copy of every remote system, so no general-purpose cache sits in front of synced data and there is no invalidation surface on it. Caching is applied only where an external contract demands it: OAuth session tokens, HelpScout read responses and transient alert throttling. A Redis read-through cache was started and stopped. Revisit if query performance on synced tables degrades or a read-heavy public feature is added.

## Amended (2026-10-31)

One exception: a cache over synced data is allowed only when its validity is recomputed from live data in the reading request's own snapshot (the reader hashes each entry's inputs in the transaction that reads them and serves only on a match). Caches invalidated by writers, a TTL or a scheduled refresh stay excluded.

The one such cache is the `GET /api/products` fragment cache (`catalog.product_list_fragments`). The hourly `catalog:check-list-fragment-drift` command rebuilds a sample, reports and deletes any fragment whose body differs under a matching hash, and deletes fragments of deleted products.
