<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * A support agent in the customer service system.
 */
final readonly class SupportAgent
{
    public function __construct(
        public int $id,
        public string $email,
        public string $firstName,
        public string $lastName,
    ) {
        Assert::greaterThan($id, 0, 'Agent ID must be positive');
        Assert::notEmpty($email, 'Agent email cannot be empty');
    }
}
