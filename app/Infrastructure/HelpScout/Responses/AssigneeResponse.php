<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\ConversationAssignee;
use Spatie\LaravelData\Data;

/**
 * Assignee (HelpScout team member) information from a conversation.
 */
final class AssigneeResponse extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $email,
        public readonly ?string $photoUrl,
    ) {}

    /**
     * Transform to Domain value object.
     */
    public function toDomain(): ConversationAssignee
    {
        return new ConversationAssignee(
            id: $this->id,
            firstName: $this->firstName ?? '',
            lastName: $this->lastName ?? '',
            photoUrl: $this->photoUrl,
        );
    }
}
