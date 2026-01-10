# Implementation Log: Issue #108 - ShopWired Order Persistence

## Overview
Enable local database storage for ShopWired orders with repository pattern.

## Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-01-11 | Use dedicated `shopwired` PostgreSQL schema | Organizes ~30-40 tables cleanly; matches plan |
| 2026-01-11 | Domain Order gets `id` property (ShopWired's ID) | External identifier for persistence; internal UUID stays in Infrastructure |
| 2026-01-11 | JSONB for addresses/customer/shipping | Immutable snapshots; no joins needed |
| 2026-01-11 | Repository in `Application/Contracts/Shopwired/` | Dependency inversion - Application defines, Infrastructure implements |
| 2026-01-11 | `SaveManyResult` continues on individual failures | Resilient bulk sync; returns detailed failure info |

## Implementation Progress

### Phase 1: Domain Changes
- [ ] Add `id` property to `Order` value object
- [ ] Update `OrderResponse::toDomain()` to include `id`
- [ ] Update existing Order tests

### Phase 2: Application Layer
- [ ] Create `OrderRepositoryInterface`
- [ ] Create `SaveManyResult` value object
- [ ] Create `SyncResult` value object

### Phase 3: Database Migrations
- [ ] Create `shopwired` schema migration
- [ ] Create `shopwired.orders` table migration
- [ ] Create `shopwired.order_products` table migration
- [ ] Create `shopwired.order_discounts` table migration

### Phase 4: Eloquent Models
- [ ] Create `OrderModel`
- [ ] Create `OrderProductModel`
- [ ] Create `OrderDiscountModel`

### Phase 5: Repository & Mappers
- [ ] Create `StatusTypeToLifecycleMapper`
- [ ] Create `EloquentOrderRepository`

### Phase 6: Sync Use Case
- [ ] Create `SyncOrdersUseCase`
- [ ] Create `SyncShopwiredOrdersJob`
- [ ] Register hourly schedule

### Phase 7: Tests
- [ ] Unit tests for value objects
- [ ] Integration tests for repository
- [ ] Feature tests for use case

### Phase 8: Documentation
- [ ] Update Infrastructure/ShopWired/CLAUDE.md

## PR Notes
<!-- Draft PR description here -->

**Summary:**
- Implement local PostgreSQL persistence for ShopWired orders
- Add `OrderRepositoryInterface` with Eloquent implementation
- Create hourly sync job with 2hr overlap window
- Bulk saves continue on individual failures

**Test Plan:**
- [ ] Run migrations: `php artisan migrate`
- [ ] Verify linting: `make lint`
- [ ] Run test suite: `make test`
- [ ] Manual roundtrip via tinker

## Open Questions
None currently.
