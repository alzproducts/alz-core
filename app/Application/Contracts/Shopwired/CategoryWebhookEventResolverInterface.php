<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Category\Enums\CategoryWebhookIntent;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Resolves platform-specific webhook topic strings to generic category business intents.
 */
interface CategoryWebhookEventResolverInterface
{
    /** @throws InvalidApiResponseException When the topic string is not a recognised category webhook topic */
    public function resolve(string $topic): CategoryWebhookIntent;
}
