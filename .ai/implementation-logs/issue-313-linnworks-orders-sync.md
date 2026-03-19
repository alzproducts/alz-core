# Implementation Log: #313 — Linnworks Orders Sync

## Status: In Progress

## Plan
`.ai/plans/2026-03-19_313-linnworks-orders-sync.md`

## Decision Log

| # | Decision | Choice | Why |
|---|----------|--------|-----|
| 1 | Phase 0 (API validation) | Deferred | Implementing code first; validate via tinker before merging |
| 2 | Domain value object fields | ~45 flat fields, all strings/primitives | No enums — defer until backsync reveals value space |
| 3 | Result type | Custom `OrderSyncResult` with `latestLastUpdated` | Cursor use case needs max LastUpdated from sync to advance cursor |
| 4 | OrderSyncTier | 4 cases (Hourly/Daily/Weekly/Full) | Cursor tier handled by separate job/use case |
| 5 | Reuse `SyncResult` | No — need `latestLastUpdated` field | Cursor advancement requires tracking max date across batches |

## Implementation Progress

- [ ] Phase 1: Domain value object
- [ ] Phase 2: Application contracts, results, enums, cursor type
- [ ] Phase 3: Migration + Eloquent model
- [ ] Phase 4: Response DTOs + OrderClient + Repository
- [ ] Phase 5: Use cases (orders + cursor)
- [ ] Phase 6: Jobs
- [ ] Phase 7: Schedule + service provider wiring
- [ ] Linting pass
- [ ] Tests

## PR Notes
_To be drafted before PR creation_
