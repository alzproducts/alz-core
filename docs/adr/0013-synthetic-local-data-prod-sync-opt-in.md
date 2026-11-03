# Synthetic local data; prod sync is opt-in

Local databases used to be filled by dispatching the real ShopWired and Linnworks sync jobs, which put production data (and, with the PII option, customers and orders) on every laptop. Integration tests and perf work also depended on whatever the last sync had pulled in.

A local reset now fills the database with a synthetic dataset, one **seed slice** at a time (products first), generated from a committed **seed profile** that holds only counts and structural distributions. The profile holds no rows, prices, margins or names, and is exported once from production by a person, through a read-only query. Domains without a slice stay empty locally rather than falling back to real data, so fake and real SKUs never mix. The real sync survives only as an explicitly named opt-in command for debugging problems that occur only in production.

## Considered Options

- **Keep syncing from production, scrubbed:** rejected, because the data still depends on prod being reachable, scrubbing PII from synced rows is easy to get wrong, and the dataset isn't reproducible across machines.
- **Fake slice plus real sync for the other domains:** rejected, because real purchase orders and ratings would reference SKUs that don't exist in the fake catalog.
- **Commit price and margin distributions to the profile:** rejected, because they are commercially sensitive aggregates. Prices come from synthetic ranges chosen to exercise rounding.

## Consequences

- `make test-integration` and the `/api/products` perf eval run on the seeded dataset, so a seed slice is part of their contract.
- Re-export the profile when production's shape drifts.
