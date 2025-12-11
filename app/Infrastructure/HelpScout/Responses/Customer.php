<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use Spatie\LaravelData\Data;

/**
 * Primary customer information from a HelpScout conversation.
 *
 * Note: HelpScout uses 'first' and 'last' instead of 'firstName' and 'lastName'
 * in the primaryCustomer object (unlike other user objects).
 */
final class Customer extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly ?string $first,
        public readonly ?string $last,
        public readonly ?string $email,
    ) {}
}
