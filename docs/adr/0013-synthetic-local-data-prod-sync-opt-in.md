# Synthetic local data is the only local data source

Local databases used to be filled by dispatching the real ShopWired and Linnworks sync jobs, which put production data (and, with the PII option, customers and orders) on every laptop. Integration tests and perf work also depended on whatever the last sync had pulled in.

A local reset now fills the database with a synthetic dataset, one **seed slice** at a time (products first), generated from a committed **seed profile** that holds only counts and structural distributions. The profile holds no rows, prices, margins or names, and is exported once from production by a person, through a read-only query. Domains without a slice stay empty locally rather than falling back to real data, so fake and real SKUs never mix. The synthetic seed is the only local data source.

**Amended (COR-272):** the opt-in prod-sync command this ADR first kept for production-only debugging (`dev:sync-from-prod`, with `make sync-from-prod` and `make db-reset-full-pii`) is deleted: it was a route from a local checkout to production data. Debugging a production-only problem now starts from production logs, not a local copy.

**Amended (COR-297):** a seed profile that holds commercially sensitive aggregates, such as the orders profile's exact price tuples with counts, stays local and gitignored and is never committed. It keeps the other rules: aggregates only, no rows, names or references, and one human-run read-only export. Its slice reads prices from the local profile at runtime or from synthetic ranges, never from committed copies of real tuples. The products profile stays committed and price-free.

## Considered Options

- **Keep syncing from production, scrubbed:** rejected, because the data still depends on prod being reachable, scrubbing PII from synced rows is easy to get wrong, and the dataset isn't reproducible across machines.
- **Fake slice plus real sync for the other domains:** rejected, because real purchase orders and ratings would reference SKUs that don't exist in the fake catalog.
- **Keep the real sync as an opt-in command:** first accepted, then dropped (COR-272), because any command that pulls production data is a production route an agent in a local checkout can run.
- **Commit price and margin distributions to the profile:** rejected, because they are commercially sensitive aggregates. Prices come from synthetic ranges chosen to exercise rounding.

## Consequences

- `make test-integration` and the `/api/products` perf eval run on the seeded dataset, so a seed slice is part of their contract.
- Re-export the profile when production's shape drifts.
