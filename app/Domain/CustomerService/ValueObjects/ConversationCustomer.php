<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * The customer who initiated a conversation.
 */
final readonly class ConversationCustomer
{
    public function __construct(
        public int $id,
        public ?string $firstName,
        public ?string $lastName,
        public ?string $email,
    ) {
        Assert::greaterThan($id, 0, 'Customer ID must be positive');
    }
}
