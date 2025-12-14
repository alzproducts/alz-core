<?php

declare(strict_types=1);

namespace App\Domain\CustomerService\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * A customer service mailbox/inbox.
 */
final readonly class Mailbox
{
    public function __construct(
        public int $id,
        public string $name,
        public string $email,
        public string $slug,
    ) {
        Assert::greaterThan($id, 0, 'Mailbox ID must be positive');
        Assert::notEmpty($name, 'Mailbox name cannot be empty');
        Assert::notEmpty($slug, 'Mailbox slug cannot be empty');
    }
}
