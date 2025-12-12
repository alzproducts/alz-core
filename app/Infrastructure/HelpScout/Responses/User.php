<?php

declare(strict_types=1);

namespace App\Infrastructure\HelpScout\Responses;

use App\Domain\CustomerService\ValueObjects\SupportAgent;
use Spatie\LaravelData\Data;

/**
 * HelpScout user (team member) information.
 *
 * Used for user mapping: Supabase email → HelpScout user ID.
 */
final class User extends Data
{
    public function __construct(
        public readonly int $id,
        public readonly ?string $email,
        public readonly ?string $firstName,
        public readonly ?string $lastName,
        public readonly ?string $photoUrl,
        public readonly ?string $role,
        public readonly ?string $timezone,
    ) {}

    /**
     * Check if this user's email matches (case-insensitive).
     */
    public function matchesEmail(string $email): bool
    {
        if ($this->email === null) {
            return false;
        }

        return \strcasecmp($this->email, $email) === 0;
    }

    /**
     * Transform to Domain value object.
     */
    public function toDomain(): SupportAgent
    {
        return new SupportAgent(
            id: $this->id,
            email: $this->email ?? '',
            firstName: $this->firstName ?? '',
            lastName: $this->lastName ?? '',
        );
    }
}
