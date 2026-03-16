<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Enums;

/**
 * Webhook event topics from ShopWired.
 *
 * Each topic maps to a specific subject type and indicates whether
 * the event is a deletion. Standard events include the full entity
 * in `data.object`; custom events have lightweight payloads.
 *
 * IMPORTANT: A separate {@see \App\Application\Shopwired\Enums\WebhookTopic}
 * exists in the Application layer for idempotency tracking (subset of handled
 * topics only). When adding new handled topics, update BOTH enums.
 *
 * @see https://shopwired.readme.io/reference/webhooks
 */
enum WebhookTopic: string
{
    // Orders
    case OrderUpdated = 'order.updated';
    case OrderDeleted = 'order.deleted';
    case OrderFinalized = 'order.finalized';
    case OrderStatusChanged = 'order.status_changed';

    // Order Refunds
    case OrderRefundCreated = 'order.refund.created';
    case OrderRefundDeleted = 'order.refund.deleted';

    // Products
    case ProductCreated = 'product.created';
    case ProductUpdated = 'product.updated';
    case ProductDeleted = 'product.deleted';
    case ProductStockChanged = 'product.stock_changed';

    // Customers
    case CustomerCreated = 'customer.created';
    case CustomerUpdated = 'customer.updated';
    case CustomerDeleted = 'customer.deleted';

    // Categories (not handled, defined for forward compatibility)
    case CategoryCreated = 'category.created';
    case CategoryUpdated = 'category.updated';
    case CategoryDeleted = 'category.deleted';

    // Brands (not handled)
    case BrandCreated = 'brand.created';
    case BrandUpdated = 'brand.updated';
    case BrandDeleted = 'brand.deleted';

    // Tags (not handled)
    case TagCreated = 'tag.created';
    case TagUpdated = 'tag.updated';
    case TagDeleted = 'tag.deleted';

    // Batch (not handled)
    case BatchCompleted = 'batch.completed';

    public function subjectType(): WebhookSubjectType
    {
        return match ($this) {
            self::OrderUpdated,
            self::OrderDeleted,
            self::OrderFinalized,
            self::OrderStatusChanged => WebhookSubjectType::Order,

            self::OrderRefundCreated,
            self::OrderRefundDeleted => WebhookSubjectType::OrderRefund,

            self::ProductCreated,
            self::ProductUpdated,
            self::ProductDeleted,
            self::ProductStockChanged => WebhookSubjectType::Product,

            self::CustomerCreated,
            self::CustomerUpdated,
            self::CustomerDeleted => WebhookSubjectType::Customer,

            self::CategoryCreated,
            self::CategoryUpdated,
            self::CategoryDeleted => WebhookSubjectType::Category,

            self::BrandCreated,
            self::BrandUpdated,
            self::BrandDeleted => WebhookSubjectType::Brand,

            self::TagCreated,
            self::TagUpdated,
            self::TagDeleted => WebhookSubjectType::Tag,

            self::BatchCompleted => WebhookSubjectType::Batch,
        };
    }

    public function isDeleteEvent(): bool
    {
        return match ($this) {
            self::OrderDeleted,
            self::OrderRefundDeleted,
            self::ProductDeleted,
            self::CustomerDeleted,
            self::CategoryDeleted,
            self::BrandDeleted,
            self::TagDeleted => true,

            self::OrderUpdated,
            self::OrderFinalized,
            self::OrderStatusChanged,
            self::OrderRefundCreated,
            self::ProductCreated,
            self::ProductUpdated,
            self::ProductStockChanged,
            self::CustomerCreated,
            self::CustomerUpdated,
            self::CategoryCreated,
            self::CategoryUpdated,
            self::BrandCreated,
            self::BrandUpdated,
            self::TagCreated,
            self::TagUpdated,
            self::BatchCompleted => false,
        };
    }
}
