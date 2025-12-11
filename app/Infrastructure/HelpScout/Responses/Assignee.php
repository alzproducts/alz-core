<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use Spatie\LaravelData\Data;

/**
 * Assignee (HelpScout team member) information from a conversation.
 */
final class Assignee extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $email,
        public readonly ?string $photoUrl,
    ) {}
}
