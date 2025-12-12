<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\ConversationTag;
use Spatie\LaravelData\Data;

/**
 * Tag attached to a HelpScout conversation.
 */
final class TagResponse extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $tag,
        public readonly string $color,
    ) {}

    /**
     * Transform to Domain value object.
     */
    public function toDomain(): ConversationTag
    {
        return new ConversationTag(
            id: $this->id,
            name: $this->tag,
            color: $this->color,
        );
    }
}
