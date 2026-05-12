<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Shopwired\DTOs\WebhookOrderRefundResultDTO;
use App\Domain\Catalog\Order\ValueObjects\Order;
use App\Domain\Catalog\Order\ValueObjects\OrderStatus;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\ValueObjects\IntId;

/**
 * Parses order-related data from webhook event payloads.
 *
 * Bridges Application → Infrastructure for order parsing.
 * Implementations use platform-specific response DTOs.
 */
interface OrderWebhookParserInterface
{
    /**
     * Parse a full Order domain object from the webhook event.data payload.
     *
     * Returns null when the payload is recognisable but incomplete (e.g. missing
     * reference) — the caller decides whether to skip or fail.
     *
     * @param array<string, mixed> $data The event.data payload (contains 'object' key)
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseOrder(array $data): ?Order;

    /**
     * Parse an OrderStatus from an `order.status_changed` event.data payload.
     *
     * @param array<string, mixed> $data The event.data payload (contains 'newStatus' key)
     *
     * @throws InvalidApiResponseException When the status name is unrecognised or the payload is malformed
     */
    public function parseOrderStatus(array $data): OrderStatus;

    /**
     * Parse an OrderRefund from an `order.refund.created` event.data payload.
     *
     * Returns a DTO carrying both the parsed refund and the order ID extracted
     * from the payload body (since subjectId is the refund ID, not the order ID).
     *
     * @param array<string, mixed> $data The event.data payload (contains refund fields)
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseOrderRefund(array $data): WebhookOrderRefundResultDTO;

    /**
     * Parse the refund external ID from an `order.refund.deleted` event.data payload.
     *
     * Minimal extraction — delete payloads may omit fields beyond the ID.
     *
     * @param array<string, mixed> $data The event.data payload (contains 'object' key with 'id')
     *
     * @throws InvalidApiResponseException When the payload is missing 'object.id' or 'id' is not an integer
     */
    public function parseRefundExternalId(array $data): IntId;
}
