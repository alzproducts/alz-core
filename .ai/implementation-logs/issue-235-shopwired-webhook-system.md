# Implementation Log: Issue #235 — ShopWired Webhook System

**Plan**: `.ai/plans/2026-03-04_235-shopwired-webhook-system.md`
**Branch**: `feature/235-feat-shopwired-add-webhook-system-for-near-real-time-data-sync`
**Started**: 2026-03-04

## Decision Log

| # | Decision | Rationale |
|---|----------|-----------|
| 1 | Follow plan's 12-step implementation order | Dependencies flow naturally |
| 2 | `saveFromWebhook(entity, DateTimeImmutable)` atomic upsert | Single DB operation, not save+updateTimestamp |
| 3 | `performSave(entity, array $extra = [])` in Order/Product repos | Shares logic between save() and saveFromWebhook() |
| 4 | `updateStock(Sku)` not `updateStock(IntId)` | Webhook payload provides SKU, not external ID |
| 5 | `webhook_staleness_hours` in config, not const | Single source of truth across 6+ use cases |
| 6 | No timestamp check in reconciliation jobs | ShouldBeUnique deduplicates; job always fetches current API state |
| 7 | Constructor-injected staleness hours (no default) | config() forbidden in Application layer; no default prevents misconfiguration |
| 8 | `setTimestamp(time() - hours*3600)` for cutoff | Carbon forbidden in Application layer; modify() throws DateMalformedStringException |
| 9 | `AbstractReconcileShopwiredEntityJob` base class | Eliminates duplicated error handling across 3 jobs; `@template TEntity` for generic executeSync() |
| 10 | Custom PHPStan rules updated for abstract parent | jobMustCallOnQueue + jobHandleMustCatchThrowable exempt concrete jobs with abstract parent |
| 11 | `IntId $orderId/productId` not `int $subjectId` in custom use cases | Stronger typing; no allocation overhead from double IntId::from() |
| 12 | No staleness check in delete use cases | Delete events fire once; no reconciliation safety net; discard risk outweighs replay risk (HMAC covers security) |
| 13 | Domain event pattern for admin alerts, not SimpleMsgServiceInterface | Event system gives retry isolation, multi-listener extensibility, explicit failed() handling — standard for notifications |
| 14 | `shopwired_webhook_at` idempotency check semantics | Any newer processed webhook suppressing a custom event is safe: reconciliation job always restores authoritative state |

## Progress

- [x] Step 1: Migration + config
- [x] Step 2: Enums
- [x] Step 3: Middleware
- [x] Step 4: DTOs
- [x] Step 5: Domain entity + repository changes
- [x] Step 6a: Sync use cases (3/3)
- [x] Step 7: Reconciliation jobs (3/3 + abstract base)
- [x] Step 6b: Custom event use cases (3/3)
- [x] Step 6c: Delete use cases (3/3)
- [ ] Step 8: Controllers + routes
- [ ] Step 9: Webhook health check
- [ ] Step 10: Service provider registration
- [ ] Step 11: Tests

## Deviations from Plan

- Jobs renamed from `Refresh*` to `Reconcile*` (matches naming convention better)
- Jobs take `IntId $entityId` instead of `int $subjectId` + `DateTimeImmutable $webhookTimestamp`
- Jobs don't check webhook timestamp (ShouldBeUnique is sufficient)
- Added `AbstractReconcileShopwiredEntityJob` (not in original plan)
- Updated 2 custom PHPStan rules to support abstract parent pattern (not in plan)

## PR Notes

_Draft PR description will go here._
