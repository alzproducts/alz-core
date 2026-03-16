<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Resolvers;

use App\Application\Contracts\Shopwired\BrandWebhookEventResolverInterface;
use App\Domain\Catalog\Brand\Enums\BrandWebhookIntent;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Maps ShopWired-specific brand webhook topic strings to generic domain intents.
 */
final class ShopwiredBrandWebhookEventResolver implements BrandWebhookEventResolverInterface
{
    /** @throws InvalidApiResponseException When the topic string is not a recognised brand webhook topic */
    public function resolve(string $topic): BrandWebhookIntent
    {
        return match ($topic) {
            'brand.created', 'brand.updated' => BrandWebhookIntent::Sync,
            'brand.deleted'                  => BrandWebhookIntent::Deleted,
            default => throw new InvalidApiResponseException(
                'ShopWired',
                "Unrecognised brand webhook topic: '{$topic}'",
            ),
        };
    }
}
