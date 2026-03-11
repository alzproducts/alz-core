<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Repository for ShopWired order persistence.
 *
 * Bulk queries should use `shopwired.orders_deduplicated` view to handle edited orders
 * (same reference, multiple external_ids). See `getOrdersInDateRange()` for example.
 *
 * @extends RepositoryWriteInterface<Order>
 */
interface OrderRepositoryInterface extends RepositoryWriteInterface
{
    /**
     * Get order by customer-facing reference number.
     *
     * When multiple orders share the same reference (e.g., edited orders in ShopWired
     * where the original is cancelled and a new order is created with the same
     * customer-facing reference), returns the "active" order:
     * 1. Non-cancelled order takes priority (if exactly one exists)
     * 2. Highest external_id wins as tiebreaker (most recent ShopWired order)
     *
     * @throws ResourceNotFoundException When no order found with this reference
     * @throws DatabaseOperationFailedException On query failure
     */
    public function getByReference(int $reference): Order;

    /**
     * Get orders placed within a date range (inclusive).
     *
     * Orders are filtered by `order_placed_at` timestamp.
     * Results include all relations (products, discounts, refunds, comments).
     *
     * Note: This method applies business filters (test email exclusion, duplicate
     * deduplication). For raw unfiltered data, use getAllOrdersInDateRange().
     *
     * @return list<Order>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getOrdersInDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Get ALL orders placed within a date range (no filtering).
     *
     * Returns raw database contents without any business filtering:
     * - Includes test customer orders
     * - Includes duplicate references (cancelled + active versions)
     *
     * Use this for auditing/debugging. For business operations, use getOrdersInDateRange().
     *
     * @return list<Order>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getAllOrdersInDateRange(DateTimeImmutable $from, DateTimeImmutable $to): array;

    /**
     * Upsert an order and record the webhook event timestamp in one operation.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function saveFromWebhook(Order $order, DateTimeImmutable $webhookAt): void;

    /**
     * Update an order's status by its ShopWired external ID.
     *
     * Used by `order.status_changed` webhook for partial updates.
     * Only updates status columns — does not touch child tables.
     *
     * @throws ResourceNotFoundException When no order found with this external ID
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function updateStatus(IntId $externalId, OrderStatus $status): void;

    /**
     * Add a refund to an existing order.
     *
     * Used by `order.refund.created` webhook. Inserts a new refund row
     * without replacing existing refunds.
     *
     * @throws ResourceNotFoundException When no order found with this external ID
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function addRefund(IntId $orderExternalId, OrderRefund $refund): void;

    /**
     * Delete an order by its ShopWired external ID.
     *
     * Used by `order.deleted` webhook. Cascades to child tables
     * (products, discounts, refunds, admin comments) via FK constraints.
     *
     * @throws ResourceNotFoundException When no order found with this external ID
     * @throws DatabaseOperationFailedException On deletion failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function deleteByExternalId(IntId $externalId): void;

    /**
     * Get the webhook timestamp for an order by its ShopWired external ID.
     *
     * Returns null if the order doesn't exist or has no webhook timestamp.
     * Used for webhook idempotency checks — compare against event timestamp.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getWebhookTimestamp(IntId $externalId): ?DateTimeImmutable;

    /**
     * Update the webhook timestamp for an order by its ShopWired external ID.
     *
     * Sets `shopwired_webhook_at` to track the most recent webhook event
     * for idempotency and out-of-order protection.
     *
     * @throws ResourceNotFoundException When no order found with this external ID
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function updateWebhookTimestamp(IntId $externalId, DateTimeImmutable $timestamp): void;
}
