<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Resolvers;

use App\Application\Contracts\Shopwired\OrderWebhookEventResolverInterface;
use App\Domain\Catalog\Order\Enums\OrderWebhookIntent;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Maps ShopWired-specific order webhook topic strings to generic domain intents.
 */
final class ShopwiredOrderWebhookEventResolver implements OrderWebhookEventResolverInterface
{
    /** @throws InvalidApiResponseException When the topic string is not a recognised order webhook topic */
    public function resolve(string $topic): OrderWebhookIntent
    {
        return match ($topic) {
            'order.updated', 'order.finalized' => OrderWebhookIntent::Sync,
            'order.status_changed'             => OrderWebhookIntent::StatusChanged,
            'order.refund.created'             => OrderWebhookIntent::RefundCreated,
            'order.deleted'                    => OrderWebhookIntent::Deleted,
            default => throw new InvalidApiResponseException(
                'ShopWired',
                "Unrecognised order webhook topic: '{$topic}'",
            ),
        };
    }
}
