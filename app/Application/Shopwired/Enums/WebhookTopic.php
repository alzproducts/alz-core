<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Enums;

/**
 * ShopWired webhook topics for idempotency tracking.
 *
 * Used for per-topic idempotency tracking in the webhook_events table.
 * The string values match ShopWired's topic identifiers exactly.
 *
 * IMPORTANT: A separate {@see \App\Infrastructure\Shopwired\Enums\WebhookTopic}
 * exists in the Infrastructure layer for webhook routing/parsing (includes all
 * topics + helper methods). When adding new topics, update BOTH enums.
 */
enum WebhookTopic: string
{
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductStockChanged = 'product.stock_changed';
    case ProductDeleted = 'product.deleted';
    case OrderCreated = 'order.created';
    case OrderUpdated = 'order.updated';
    case OrderFinalized = 'order.finalized';
    case OrderStatusChanged = 'order.status_changed';
    case OrderRefundCreated = 'order.refund.created';
    case OrderDeleted = 'order.deleted';
    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';
    case CustomerDeleted = 'customer.deleted';
}
