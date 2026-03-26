<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

use App\Domain\Catalog\Brand\ValueObjects\Brand;

/**
 * Result from parsing a brand webhook payload.
 *
 * Carries the parsed brand alongside which embed fields were present
 * in the webhook payload, so downstream consumers can conditionally
 * persist only the columns that have real data.
 */
final readonly class WebhookBrandResultDTO
{
    /**
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     */
    public function __construct(
        public Brand $brand,
        public array $presentEmbeds,
    ) {}
}
