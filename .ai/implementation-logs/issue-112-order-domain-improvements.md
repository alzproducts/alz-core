# Implementation Log: Issue #112 - Order Domain Improvements

**Issue**: Complete ShopWired order domain model with missing fields and child tables
**Branch**: `feature/108-implement-shopwired-order-persistence-with-repository-pattern`
**Started**: 2026-01-12

---

## Decision Log

| # | Decision | Context | Chosen | Rationale |
|---|----------|---------|--------|-----------|
| 1 | Phase order | 4 phases in plan | Execute sequentially P1→P4 | P1 is critical bug fix, each builds on previous |

---

## Progress

### Phase 1: Fix status_id Bug ✅
- [x] Add `id` and `sortOrder` to `OrderStatus` domain object
- [x] Update `OrderStatusResponse.toDomain()` to pass new fields
- [x] Update `OrderModelMapper` to map `status_id` and `status_sort_order`
- [x] Create migration for `status_sort_order` column
- [x] Update tests (OrderStatusTest, OrderTest, SyncOrdersUseCaseTest)

### Phase 2: shipping_cost NOT NULL
- [ ] Create migration to alter column
- [ ] Update mapper null coalesce
- [ ] Update OrderModel docblock

### Phase 3: Missing Scalar Fields
- [ ] Create PreOrderStatus enum
- [ ] Add ~13 fields to Order domain
- [ ] Add countryId to OrderAddress
- [ ] Add id to OrderShipping
- [ ] Add isPreorder to OrderProduct
- [ ] Update all Response DTOs
- [ ] Update OrderModelMapper
- [ ] Create migration for new columns

### Phase 4: Child Tables
- [ ] Create OrderRefund domain + model + migration
- [ ] Create OrderAdminComment domain + model + migration
- [ ] Update Order domain with refunds/adminComments arrays
- [ ] Update EloquentOrderRepository sync logic

---

## PR Notes

_Draft PR description will be written here before creating the PR_

---

## Open Questions

_None currently_
