<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Shopwired\DTOs\WebhookCategoryResultDTO;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Parses a full category entity from a webhook event payload.
 */
interface CategoryWebhookParserInterface
{
    /**
     * Parse a Category domain object and embed metadata from the webhook event.data payload.
     *
     * @param array<string, mixed> $data The event.data payload (contains 'object' key)
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseCategory(array $data): WebhookCategoryResultDTO;
}
