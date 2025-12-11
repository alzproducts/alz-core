<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use Spatie\LaravelData\Attributes\DataCollectionOf;
use Spatie\LaravelData\Data;

/**
 * HelpScout conversations list API response.
 *
 * Wraps the _embedded.conversations array and pagination info.
 */
final class ConversationsResponse extends Data
{
    /**
     * @param array<Conversation> $conversations
     */
    public function __construct(
        #[DataCollectionOf(Conversation::class)]
        public readonly array $conversations,
        public readonly Page $page,
    ) {}

}
