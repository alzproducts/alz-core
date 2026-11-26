# Synthetic local data is the only local data source

A local reset fills the database one **seed slice** at a time from a committed **seed profile** of counts and structural distributions: no rows, prices, margins or names. A person exports it once from production through a read-only query. Domains without a slice stay empty, so fake and real SKUs never mix. No command copies production data to a local checkout (amended, COR-272); debug production-only problems from production logs.

**Amended (COR-297):** a profile holding commercially sensitive aggregates, such as the orders profile's price tuples, stays local and gitignored, under the same rules otherwise. Its slice reads prices from that profile or from synthetic ranges, never from committed copies of real tuples.

## Considered Options

- **Scrubbed production sync:** rejected. It depends on prod being reachable, scrubbing PII is easy to get wrong, and the data isn't reproducible.
- **Fake slice plus real sync for other domains:** rejected. Real records would reference SKUs missing from the fake catalog.
- **Opt-in production sync:** first accepted, then dropped (COR-272). An agent in a local checkout could run it.
- **Committed price and margin distributions:** rejected as commercially sensitive.

`make test-integration` and the `/api/products` perf eval run on the seeded dataset, so a seed slice is part of their contract.
Re-export the profile when production's shape drifts.
