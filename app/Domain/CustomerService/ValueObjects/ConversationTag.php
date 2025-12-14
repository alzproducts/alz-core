<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * A tag applied to a conversation.
 */
final readonly class ConversationTag
{
    public function __construct(
        public int $id,
        public string $name,
        public ?string $color,
    ) {
        Assert::greaterThan($id, 0, 'Tag ID must be positive');
        Assert::notEmpty($name, 'Tag name cannot be empty');
    }
}
