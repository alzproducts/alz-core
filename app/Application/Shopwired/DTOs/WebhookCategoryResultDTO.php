<?php

declare(strict_types=1);

namespace App\Application\Shopwired\DTOs;

use App\Domain\Catalog\Category\ValueObjects\Category;

/**
 * Result from parsing a category webhook payload.
 *
 * Carries the parsed category alongside which embed fields were present
 * in the webhook payload, so downstream consumers can conditionally
 * persist only the columns that have real data.
 */
final readonly class WebhookCategoryResultDTO
{
    /**
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     */
    public function __construct(
        public Category $category,
        public array $presentEmbeds,
    ) {}
}
