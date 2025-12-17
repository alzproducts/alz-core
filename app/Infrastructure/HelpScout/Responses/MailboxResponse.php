<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\Mailbox as DomainMailbox;
use App\Infrastructure\Contracts\DomainConvertibleInterface;
use Spatie\LaravelData\Data;

/**
 * HelpScout mailbox information.
 */
final class MailboxResponse extends Data implements DomainConvertibleInterface
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly string $email,
        public readonly ?string $slug,
        public readonly ?string $createdAt,
        public readonly ?string $updatedAt,
    ) {}

    /**
     * Transform to Domain value object.
     */
    public function toDomain(): DomainMailbox
    {
        return new DomainMailbox(
            id: $this->id,
            name: $this->name,
            email: $this->email,
            slug: $this->slug ?? '',
        );
    }
}
