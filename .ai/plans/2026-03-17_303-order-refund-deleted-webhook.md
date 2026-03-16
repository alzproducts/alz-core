# Plan: `order.refund.deleted` Webhook

## Context

When a refund is deleted in ShopWired, there's currently no webhook to notify us. The refund only gets removed locally during a full order sync (`SyncShopwiredOrderJob`), which is triggered by *other* order events. This means a deleted refund can persist indefinitely until an unrelated event happens to trigger reconciliation. Adding the `order.refund.deleted` webhook closes this gap.

## Approach

Follow the established patterns:
- **Delete idempotency** from `DeleteOrderUseCase` — no staleness check, catch `ResourceNotFoundException`
- **Refund reconciliation** from `CreateOrderRefundUseCase` — queue `SyncShopwiredOrderJob` after mutation
- **Admin alert** from `DeleteOrderUseCase` — refund deletion is unusual, worth flagging
- **Minimal parser** — only extract refund `id` from `data.object` (delete payloads may omit other fields)

## Changes

### 1. Domain — Add intent enum case
**`app/Domain/Catalog/Order/Enums/OrderWebhookIntent.php`**
- Add `RefundDeleted` case

### 2. Infrastructure — Add webhook topic enum case
**`app/Infrastructure/Shopwired/Enums/WebhookTopic.php`**
- Add `OrderRefundDeleted = 'order.refund.deleted'`
- Add to `subjectType()` → `WebhookSubjectType::OrderRefund`
- Add to `isDeleteEvent()` → `true`

### 3. Application — Add webhook topic enum case
**`app/Application/Shopwired/Enums/WebhookTopic.php`**
- Add `OrderRefundDeleted = 'order.refund.deleted'`

### 4. Infrastructure — Map topic to intent
**`app/Infrastructure/Shopwired/Resolvers/ShopwiredOrderWebhookEventResolver.php`**
- Add `'order.refund.deleted' => OrderWebhookIntent::RefundDeleted`

### 5. Application — Add parser interface method
**`app/Application/Contracts/Shopwired/OrderWebhookParserInterface.php`**
- Add `parseRefundExternalId(array $data): IntId`
- Only extracts `data.object.id` — minimal for delete operations

### 6. Infrastructure — Implement parser method
**`app/Infrastructure/Shopwired/Parsers/ShopwiredOrderWebhookParser.php`**
- Implement `parseRefundExternalId()` — extract `object.id` as `IntId`
- Throw `InvalidApiResponseException` if missing/malformed

### 7. Application — Add repository interface method
**`app/Application/Contracts/Shopwired/OrderRepositoryInterface.php`**
- Add `deleteRefund(IntId $orderExternalId, IntId $refundExternalId): void`
- Throws: `ResourceNotFoundException`, `DatabaseOperationFailedException`, `ExternalServiceUnavailableException`

### 8. Infrastructure — Implement repository method
**`app/Infrastructure/Shopwired/Repositories/EloquentOrderRepository.php`**
- Implement `deleteRefund()` using `eloquentGateway->query()` wrapper (same pattern as `addRefund`)
- Delete `OrderRefundModel` where `order_external_id` + `external_id` match
- Throw `ResourceNotFoundException` if no rows deleted (for idempotency in use case)

### 9. Application — Create `DeleteOrderRefundUseCase` (new file)
**`app/Application/Shopwired/UseCases/Webhooks/DeleteOrderRefundUseCase.php`**
- Dependencies: `OrderRepositoryInterface`, `LoggerInterface`, `Dispatcher`
- No staleness check (delete events are one-time)
- Catch `ResourceNotFoundException` → log + return (idempotent)
- Queue `SyncShopwiredOrderJob` for full reconciliation
- Dispatch `AdminAlertEvent` — refund deletion warrants investigation

### 10. Application — Wire into webhook service
**`app/Application/Shopwired/Services/HandleOrderWebhookService.php`**
- Add `DeleteOrderRefundUseCase` constructor dependency
- Add match arm: `RefundDeleted` → calls `deleteRefundUseCase->execute()` with parsed `refundExternalId`

### 11. Tests
- **New**: `tests/Unit/Application/Shopwired/UseCases/Webhooks/DeleteOrderRefundUseCaseTest.php`
  - Happy path: deletes, queues sync, dispatches alert
  - Idempotent: already-deleted refund → logs + returns, no sync/alert
- **Extend**: `tests/Unit/Infrastructure/Shopwired/Parsers/ShopwiredOrderWebhookParserTest.php`
  - `parseRefundExternalId`: valid payload, missing `object`, missing `id`, non-integer `id`

## Verification
1. `make lint` — all linters pass (PHPStan, Pint, PHPArkitect, Deptrac)
2. `make test` — new and existing tests pass
3. Verify match exhaustiveness in `HandleOrderWebhookService` (PHPStan enforces this)
4. **Post-deploy**: Register `order.refund.deleted` webhook topic in ShopWired (admin UI or API) — code changes alone won't trigger webhooks
