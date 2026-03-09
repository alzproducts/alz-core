<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Customer\Enums\CustomerWebhookIntent;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Resolves platform-specific webhook topic strings to generic customer business intents.
 *
 * Implementations live in Infrastructure, keeping the Application layer
 * decoupled from platform-specific topic naming.
 */
interface CustomerWebhookEventResolverInterface
{
    /**
     * Resolve a platform-specific topic string to a customer webhook intent.
     *
     * @throws InvalidApiResponseException When the topic string is not a recognised customer webhook topic
     */
    public function resolve(string $topic): CustomerWebhookIntent;
}
