<?php

declare(strict_types=1);

namespace App\Domain\Customer\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * State/Province Value Object.
 *
 * Represents a state or province for customer addresses.
 */
final readonly class State
{
    public function __construct(
        public string $name,
    ) {
        Assert::notEmpty($name, 'State name cannot be empty');
    }
}
