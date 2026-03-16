<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Brand\Enums\BrandWebhookIntent;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Resolves platform-specific webhook topic strings to generic brand business intents.
 */
interface BrandWebhookEventResolverInterface
{
    /** @throws InvalidApiResponseException When the topic string is not a recognised brand webhook topic */
    public function resolve(string $topic): BrandWebhookIntent;
}
