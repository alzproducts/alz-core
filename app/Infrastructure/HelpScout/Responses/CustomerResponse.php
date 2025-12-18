<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\ConversationCustomer;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Data;

/**
 * Primary customer information from a HelpScout conversation.
 *
 * Note: HelpScout uses 'first' and 'last' instead of 'firstName' and 'lastName'
 * in the primaryCustomer object (unlike other user objects).
 */
final class CustomerResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly int $id,
        public readonly string $type,
        public readonly ?string $first,
        public readonly ?string $last,
        public readonly ?string $email,
    ) {}

    /**
     * Transform to Domain value object.
     */
    public function toDomain(): ConversationCustomer
    {
        return new ConversationCustomer(
            id: $this->id,
            firstName: $this->first,
            lastName: $this->last,
            email: $this->email,
        );
    }
}
