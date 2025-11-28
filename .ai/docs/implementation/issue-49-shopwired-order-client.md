# Implementation Log: ShopWired OrderClient (#49)

**Issue**: [#49 - feat: Add ShopWired OrderClient](https://github.com/alzproducts/alz-core/issues/49)
**Plan**: [2025-11-28_49-shopwired-order-client.md](../plans/2025-11-28_49-shopwired-order-client.md)
**Started**: 2025-11-28

---

## Decision Log

| Decision | Rationale | Date |
|----------|-----------|------|
| Bounded Context: `Domain\Catalog\Order` | ShopWired-specific, not God Object | 2025-11-28 |
| Summary/Detail pattern | Orders can be 50KB+; optimize list payloads | 2025-11-28 |
| YAGNI Domain VOs | Infra captures all; Domain gets business essentials | 2025-11-28 |
| `listOrdersInRangeWithDetails()` method | Support Mixpanel sync (30-50 orders/day) | 2025-11-28 |
| Strict enum parsing (`::from()`) | Fail-fast on unknown values | 2025-11-28 |
| `customer.type` as string | Old-server shows string, not int | 2025-11-28 |

---

## Progress

### Phase 1: Infrastructure DTOs ✅
- [x] OrderTax.php
- [x] OrderFee.php
- [x] OrderDiscount.php
- [x] OrderRefund.php
- [x] OrderShipping.php
- [x] OrderStatus.php
- [x] OrderPartialPayment.php
- [x] OrderFileArchive.php
- [x] OrderCustomer.php
- [x] OrderAdminComment.php
- [x] OrderAddress.php
- [x] OrderProduct.php
- [x] Order.php (root)

**Note:** DTOs created without `toDomain()` for smoke-test-first approach.
Domain conversion will be added in Phase 3 after validating parsing.

### Phase 2: Smoke Test
- [ ] Parse 2000+ orders via Tinker
- [ ] Verify all order states (paid, unpaid, cancelled, shipped)
- [ ] Verify anonymized orders
- [ ] Zero parsing exceptions

### Phase 3: Domain Value Objects
- [ ] Domain enums (OrderStatusType, CustomerType, PaymentMethod)
- [ ] Domain VOs (Order, OrderStatus, OrderAddress, etc.)

### Phase 4: OrderQueryParams
- [ ] OrderQueryParams.php

### Phase 5: OrderClient
- [ ] OrderClientInterface.php
- [ ] OrderClient.php

### Phase 6: Unit Tests
- [ ] DTO tests
- [ ] Client tests
- [ ] QueryParams tests

---

## PR Notes

_Draft PR description here before creating PR_

---

## Issues Encountered

_Document blockers/unexpected issues here_
