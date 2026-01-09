<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * The support agent assigned to a conversation.
 */
final readonly class ConversationAssignee
{
    public function __construct(
        public int $id,
        public string $firstName,
        public string $lastName,
        public ?string $photoUrl,
        public ?string $email = null,
    ) {
        Assert::greaterThan($id, 0, 'Assignee ID must be positive');
    }
}
