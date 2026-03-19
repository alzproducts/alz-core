<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Resolvers;

use App\Application\Contracts\Shopwired\CustomerWebhookEventResolverInterface;
use App\Domain\Customer\Enums\CustomerWebhookIntent;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Maps ShopWired-specific customer webhook topic strings to generic domain intents.
 */
final class ShopwiredCustomerWebhookEventResolver implements CustomerWebhookEventResolverInterface
{
    /** @throws InvalidApiResponseException When the topic string is not a recognised customer webhook topic */
    public function resolve(string $topic): CustomerWebhookIntent
    {
        return match ($topic) {
            'customer.created', 'customer.updated' => CustomerWebhookIntent::Sync,
            'customer.deleted' => CustomerWebhookIntent::Deleted,
            default => throw new InvalidApiResponseException(
                'ShopWired',
                "Unrecognised customer webhook topic: '{$topic}'",
            ),
        };
    }
}
