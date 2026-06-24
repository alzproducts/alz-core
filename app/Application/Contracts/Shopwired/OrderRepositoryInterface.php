<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Application\Shopwired\Enums\OrderQueryMode;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderRefund;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
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
     * @throws RecordNotFoundException When no order found with this reference
     * @throws DatabaseOperationFailedException On query failure
     */
    public function getByReference(int $reference): Order;

    /**
     * Get orders placed within a date range (inclusive), filtered by `order_placed_at`.
     *
     * Results include all relations (products, discounts, refunds, comments).
     *
     * Mode selects the filtering applied:
     * - OrderQueryMode::Filtered — business view: test-email exclusion + reference
     *   deduplication (reads the orders_deduplicated view). Use for business operations.
     * - OrderQueryMode::Raw — raw database contents, no filtering (test orders and
     *   duplicate cancelled/active references included). Use for auditing/debugging.
     *
     * @return list<Order>
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function getOrdersInDateRange(DateTimeImmutable $from, DateTimeImmutable $to, OrderQueryMode $mode): array;

    /**
     * Upsert an order from webhook data.
     *
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function saveFromWebhook(Order $order): void;

    /**
     * Update an order's status by its ShopWired external ID.
     *
     * Used by `order.status_changed` webhook for partial updates.
     * Only updates status columns — does not touch child tables.
     *
     * @throws RecordNotFoundException When no order found with this external ID
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
     * @throws RecordNotFoundException When no order found with this external ID
     * @throws DatabaseOperationFailedException On query failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function addRefund(IntId $orderExternalId, OrderRefund $refund): void;

    /**
     * Delete a specific refund from an order.
     *
     * Used by `order.refund.deleted` webhook. Removes a single refund row
     * matching both the order and refund external IDs.
     *
     * @throws RecordNotFoundException When no refund found with these external IDs
     * @throws DatabaseOperationFailedException On deletion failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function deleteRefund(IntId $orderExternalId, IntId $refundExternalId): void;

    /**
     * Delete an order by its ShopWired external ID.
     *
     * Used by `order.deleted` webhook. Cascades to child tables
     * (products, discounts, refunds, admin comments) via FK constraints.
     *
     * @throws RecordNotFoundException When no order found with this external ID
     * @throws DatabaseOperationFailedException On deletion failure
     * @throws DuplicateRecordException On constraint violation
     * @throws ExternalServiceUnavailableException When database temporarily unavailable
     */
    public function deleteByExternalId(IntId $externalId): void;
}
