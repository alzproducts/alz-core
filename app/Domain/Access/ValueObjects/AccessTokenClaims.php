<?php

declare(strict_types=1);

namespace App\Domain\Access\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Identity and assurance claims of a verified access token.
 */
final readonly class AccessTokenClaims
{
    private const string MFA_ASSURANCE_LEVEL = 'aal2';

    /**
     * @param list<string>|null $departments
     */
    public function __construct(
        public string $userId,
        public string $email,
        public string $aal,
        public bool $isApproved,
        public ?string $roleName,
        public ?array $departments,
    ) {
        Assert::uuid($userId, 'User ID must be a valid UUID');
        Assert::email($email, 'User email must be valid');
    }

    public function isMfaVerified(): bool
    {
        return $this->aal === self::MFA_ASSURANCE_LEVEL;
    }

    public function toAuthenticatedUser(): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: $this->userId,
            email: $this->email,
            isApproved: $this->isApproved,
            roleName: $this->roleName,
            departments: $this->departments,
        );
    }
}
