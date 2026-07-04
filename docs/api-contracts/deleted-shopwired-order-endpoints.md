# Deleted ShopWired Order API Endpoints

Searchable record of working ShopWired API endpoint implementations deleted in COR-208.
Pre-deletion SHA: `0fe7d4afdbfd87555e01cf54398b88b096c2732e`

## Endpoints

### GET /orders/search?keywords=

- **Method:** `OrderClient::searchOrders(string $keyword)`
- **Response:** Wrapped array → `list<Order>` (standard mode, no products/customFields)
- **Parser:** `ShopwiredResponseParserTrait::parseWrappedArrayToDomain()`

### GET /orders/count

- **Method:** `OrderClient::getOrderCount()`
- **Response:** `{count: n}` → `int`
- **Parser:** `ShopwiredResponseParserTrait::parseCountResponse()`

### GET /orders/count?status={statusId}

- **Method:** `OrderClient::getOrderCountByStatus(int $statusId)`
- **Response:** `{count: n}` → `int`
- **Parser:** `ShopwiredResponseParserTrait::parseCountResponse()`

### POST /orders/{id}/status

- **Method:** `OrderClient::updateOrderStatus(int $orderId, OrderLifecycleStatus $status, bool $notifyCustomer, ?string $trackingUrl)`
- **Request body:** `{status: int, ...OrderStatusUpdateOptions}`
- **Mapper:** `OrderLifecycleStatusMapper::toShopwiredId()` converted domain enum to ShopWired status ID
- **Options:** `OrderStatusUpdateOptions` carried `sendEmail` and `trackingUrl` fields

## Deletion rationale

All four endpoints had zero production callers, zero test/mock usage. The `updateOrderStatus` flow was superseded by the webhook-driven `UpdateOrderStatusUseCase` which writes locally and dispatches a sync job rather than calling back to ShopWired. Count and search endpoints were never integrated into any use case.
