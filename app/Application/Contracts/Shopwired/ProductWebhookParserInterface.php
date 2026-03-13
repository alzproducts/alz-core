<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Shopwired\DTOs\StockChangeDTO;
use App\Application\Shopwired\DTOs\WebhookProductResultDTO;
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
     * Parse a product from the webhook event.data payload.
     *
     * Returns the domain Product along with which embed fields were present,
     * so downstream consumers can conditionally persist embed-dependent columns.
     *
     * @param array<string, mixed> $data The event.data payload (contains 'object' key)
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseProduct(array $data): WebhookProductResultDTO;

    /**
     * Parse stock change data from a `product.stock_changed` event.data payload.
     *
     * @param array<string, mixed> $data The event.data payload (contains sku, is_variation, new_quantity)
     *
     * @throws InvalidApiResponseException When the payload structure does not match the expected schema
     */
    public function parseStockChange(array $data): StockChangeDTO;
}
