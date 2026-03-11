<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\Enums\ProductWebhookIntent;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Resolves platform-specific webhook topic strings to generic product business intents.
 *
 * Implementations live in Infrastructure, keeping the Application layer
 * decoupled from platform-specific topic naming.
 */
interface ProductWebhookEventResolverInterface
{
    /**
     * Resolve a platform-specific topic string to a product webhook intent.
     *
     * @throws InvalidApiResponseException When the topic string is not a recognised product webhook topic
     */
    public function resolve(string $topic): ProductWebhookIntent;
}
