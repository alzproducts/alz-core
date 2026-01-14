# Implementation Log: Issue #117 - ShopWired Customer Sync

## Overview
- **Issue**: #117 - Implement ShopWired customer sync with weekly full-refresh strategy
- **Branch**: `feature/117-implement-shopwired-customer-sync-with-weekly-full-refresh-strategy`
- **Started**: 2026-01-14

## Decision Log

| Decision | Choice | Rationale |
|----------|--------|-----------|
| Sync strategy | Weekly full refresh | 60k customers @ 60 req/min = ~10 min. Simple, catches updates |
| Sort order | `created_asc` | Deterministic ordering for pagination consistency |
| Table design | 10 fields + sync tracking | Lean table, expandable via migration + re-sync |
| **Memory pattern** | **Generator (yield batches)** | 60k customers can't fit in memory. Yield pages of ~100, save each batch |
| Batch vs individual | Yield batches (list<Customer>) | Keeps "when to save" in Application layer, efficient DB batch inserts |
| Progress logging | Every ~1000 customers | Avoid log noise (600 pages), but show progress |

## Implementation Progress

### Completed
- [x] CustomerSort enum
- [x] CustomerQueryParams.withSort()

### In Progress
- [ ] ShopwiredPaginator::pages() generator
- [ ] CustomerClientInterface.iterateAllCustomerBatches()
- [ ] CustomerClient implementation

### Pending
- [ ] Migration: shopwired_customers table
- [ ] CustomerRepositoryInterface
- [ ] CustomerRepository (upsert)
- [ ] SyncCustomersUseCase (batch loop pattern)
- [ ] SyncShopwiredCustomersJob
- [ ] Weekly schedule

### Deferred
(none yet)

### Issues Encountered
- **Memory constraint discovered**: Original plan used `fetchAll()` which loads 60k customers into memory. Pivoted to generator pattern.

## PR Notes
(draft PR description here before creating)
