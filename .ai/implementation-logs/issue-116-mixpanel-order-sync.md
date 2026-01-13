# Implementation Log: Issue #116 - Mixpanel Order Sync

## Overview
Nightly job to sync orders to Mixpanel, catching missed front-end events.

**Plan Document:** `.ai/plans/2026-01-13_116-mixpanel-order-sync.md`
**Branch:** `feature/116-implement-nightly-mixpanel-order-sync-to-catch-missed-front-end-events`

---

## Decision Log

| Date | Decision | Rationale |
|------|----------|-----------|
| 2026-01-14 | Use existing `Order` value object with products | Already has detail mode with products populated |
| 2026-01-14 | Follow `SyncAdSpendUseCase` pattern | Consistent with existing Mixpanel sync patterns |
| 2026-01-14 | Pre-export deduplication via `order_id_hashed` | Mixpanel can't dedupe on custom properties |
| 2026-01-14 | Rename financial fields to include "net" suffix | ShopWired `subTotal`, `shippingTotal` are NET (excl. VAT), `total` is GROSS |
| 2026-01-14 | Send NET shipping to Mixpanel | Matches frontend: `total_excl_vat = sub_total + shipping_total` |
| 2026-01-14 | Rename `shipping_cost` to `shipping_charge_net` | "cost" ambiguous, "charge" clearer for customer amounts |
| 2026-01-14 | Rename `OrderShipping.value` to `chargeNet` | Consistency with other NET fields |

---

## Implementation Progress

### Phase 1: Infrastructure Layer
- [ ] Add `getExistingOrderHashes()` to `MixpanelClientInterface`
- [ ] Add `importOrders()` to `MixpanelClientInterface`
- [ ] Implement Export API in `MixpanelClient`
- [ ] Implement Import API for orders in `MixpanelClient`
- [ ] Create `MixpanelCheckoutCompletedDTO`
- [ ] Create `MixpanelProductPurchasedDTO`

### Phase 2: Repository Layer
- [ ] Add `getOrdersInDateRange()` to `OrderRepositoryInterface`
- [ ] Implement in `EloquentOrderRepository`

### Phase 3: Application Layer
- [ ] Create `SyncOrdersToMixpanelResult` value object
- [ ] Create `SyncOrdersToMixpanelUseCase`

### Phase 4: Presentation Layer
- [ ] Create `SyncOrdersToMixpanelJob`
- [ ] Add schedule to `routes/console.php`

### Phase 5: Testing
- [ ] Unit tests for DTOs
- [ ] Unit tests for use case
- [ ] Integration tests for repository

---

## Technical Notes

### Deduplication Strategy
- Export existing `order_id_hashed` values from Mixpanel before import
- Filter out orders already tracked by front-end
- Fail-fast if export fails (prevents duplicate imports)

### Insert ID Format
- Checkout Completed: `CC-{hash32}` (35 chars max)
- Product Purchased: `PP-{hash16}-{skuHash8}` (28 chars max)

### Time Buffer
- 4-hour buffer on `to` boundary (Mixpanel ingestion delay)
- 24-hour cushion on export `from` (edge case coverage)

---

## PR Notes

*(Draft PR description - to be finalized before PR creation)*

### Summary
- Implements nightly Mixpanel order sync to catch orders missed by front-end JS SDK
- Pre-export deduplication prevents duplicates with front-end tracked events
- Events use `order_id_hashed` as join key (matches front-end schema)

### Test Plan
- [ ] Unit tests pass with mutation testing
- [ ] Manual verification in Mixpanel Live View
- [ ] Verify `source: backend-sync` property distinguishes backend events

Closes #116
