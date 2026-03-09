<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Domain\Catalog\Product\ValueObjects\Product;
use App\Domain\Exceptions\Api\InvalidApiResponseException;

/**
 * Parses a full product entity from a webhook event payload.
 *
 * Bridges Application → Infrastructure for full entity parsing.
 * Implementations use platform-specific response DTOs.
 */
interface ProductWebhookParserInterface
{
    /**
     * Parse a full Product domain object from the webhook event.data payload.
     *
     * @param array<string, mixed> $data The event.data payload (contains 'object' key)
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseProduct(array $data): Product;
}
