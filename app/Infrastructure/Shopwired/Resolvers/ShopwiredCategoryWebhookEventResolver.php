<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Resolvers;

use App\Application\Contracts\Shopwired\CategoryWebhookEventResolverInterface;
use App\Domain\Catalog\Category\Enums\CategoryWebhookIntent;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Maps ShopWired-specific category webhook topic strings to generic domain intents.
 */
final class ShopwiredCategoryWebhookEventResolver implements CategoryWebhookEventResolverInterface
{
    /** @throws InvalidApiResponseException When the topic string is not a recognised category webhook topic */
    public function resolve(string $topic): CategoryWebhookIntent
    {
        return match ($topic) {
            'category.created', 'category.updated' => CategoryWebhookIntent::Sync,
            'category.deleted' => CategoryWebhookIntent::Deleted,
            default => throw new InvalidApiResponseException(
                'ShopWired',
                "Unrecognised category webhook topic: '{$topic}'",
            ),
        };
    }
}
