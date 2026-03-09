<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Order\Enums\OrderWebhookIntent;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Resolves platform-specific webhook topic strings to generic order business intents.
 *
 * Implementations live in Infrastructure, keeping the Application layer
 * decoupled from platform-specific topic naming.
 */
interface OrderWebhookEventResolverInterface
{
    /**
     * Resolve a platform-specific topic string to an order webhook intent.
     *
     * @throws InvalidApiResponseException When the topic string is not a recognised order webhook topic
     */
    public function resolve(string $topic): OrderWebhookIntent;
}
