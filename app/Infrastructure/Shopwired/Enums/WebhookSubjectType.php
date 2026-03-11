<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Enums;

/**
 * Subject types for ShopWired webhook events.
 *
 * Maps to the `subjectType` field in webhook payloads.
 * Only a subset of these are handled by our webhook controllers;
 * the rest are defined for completeness and forward compatibility.
 *
 * @see https://shopwired.readme.io/reference/webhooks
 */
enum WebhookSubjectType: string
{
    case Order = 'order';
    case OrderRefund = 'order_refund';
    case Product = 'product';
    case Customer = 'customer';
    case Category = 'category';
    case Brand = 'brand';
    case Tag = 'tag';
    case Batch = 'batch';
}
