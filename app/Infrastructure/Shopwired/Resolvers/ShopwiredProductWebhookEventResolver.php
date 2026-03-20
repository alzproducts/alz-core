<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Resolvers;

use App\Application\Contracts\Shopwired\ProductWebhookEventResolverInterface;
use App\Domain\Catalog\Product\Enums\ProductWebhookIntent;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Maps ShopWired-specific product webhook topic strings to generic domain intents.
 */
final class ShopwiredProductWebhookEventResolver implements ProductWebhookEventResolverInterface
{
    /** @throws InvalidApiResponseException When the topic string is not a recognised product webhook topic */
    public function resolve(string $topic): ProductWebhookIntent
    {
        return match ($topic) {
            'product.created', 'product.updated' => ProductWebhookIntent::Sync,
            'product.stock_changed' => ProductWebhookIntent::StockChanged,
            'product.deleted' => ProductWebhookIntent::Deleted,
            default => throw new InvalidApiResponseException(
                'ShopWired',
                "Unrecognised product webhook topic: '{$topic}'",
            ),
        };
    }
}
