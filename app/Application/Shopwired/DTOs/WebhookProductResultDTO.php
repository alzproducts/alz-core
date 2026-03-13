<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

use App\Domain\Catalog\Product\ValueObjects\Product;

/**
 * Result from parsing a product webhook payload.
 *
 * Carries the parsed product alongside which embed fields were present
 * in the webhook payload, so downstream consumers can conditionally
 * persist only the columns that have real data.
 */
final readonly class WebhookProductResultDTO
{
    /**
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     */
    public function __construct(
        public Product $product,
        public array $presentEmbeds,
    ) {}
}
