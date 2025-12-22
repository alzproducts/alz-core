<?php

declare(strict_types=1);

namespace App\Presentation\Http\Auth;

use App\Domain\Access\ValueObjects\AuthenticatedUser;
use App\Presentation\Http\Auth\Exceptions\InvalidJwtClaimsException;
use stdClass;

/**
 * Validates and extracts claims from a decoded Supabase JWT.
 *
 * This class is responsible for:
 * 1. Validating required claims exist and have correct types
 * 2. Extracting app_metadata claims (injected by Supabase Edge Function)
 * 3. Failing fast with clear errors if the JWT structure is unexpected
 *
 * Supabase JWT Structure (expected):
 * {
 *   "sub": "uuid",           // Required: User ID
 *   "email": "user@...",     // Required: User email
 *   "aal": "aal1|aal2",      // Required: Authentication Assurance Level
 *   "app_metadata": {        // Optional: Custom claims from Edge Function
 *     "is_approved": bool,
 *     "role_name": string,
 *     "departments_summary": string
 *   }
 * }
 */
final readonly class SupabaseJwtParser
{
    private function __construct(
        public string $userId,
        public string $email,
        public string $aal,
        public bool $isApproved,
        public ?string $roleName,
        public ?string $departmentsSummary,
    ) {}

    /**
     * Parse and validate claims from a decoded JWT.
     *
     * @param stdClass $decoded The decoded JWT payload from Firebase\JWT\JWT::decode()
     *
     * @throws InvalidJwtClaimsException If required claims are missing or malformed
     */
    public static function fromDecodedJwt(stdClass $decoded): self
    {
        // Validate and extract required claims
        self::validateRequiredClaim($decoded, 'sub', 'string');
        self::validateRequiredClaim($decoded, 'email', 'string');

        /** @var string $userId */
        $userId = $decoded->sub;
        /** @var string $email */
        $email = $decoded->email;

        $aal = self::extractAal($decoded);
        [$isApproved, $roleName, $departmentsSummary] = self::extractAppMetadata($decoded);

        return new self(
            userId: $userId,
            email: $email,
            aal: $aal,
            isApproved: $isApproved,
            roleName: $roleName,
            departmentsSummary: $departmentsSummary,
        );
    }

    /**
     * Extract AAL (Authentication Assurance Level) from JWT.
     * Defaults to 'aal1' if not present (password-only auth).
     *
     * @throws InvalidJwtClaimsException
     */
    private static function extractAal(stdClass $decoded): string
    {
        if (!isset($decoded->aal)) {
            return 'aal1';
        }

        if (!\is_string($decoded->aal)) {
            throw InvalidJwtClaimsException::invalidType('aal', 'string', \gettype($decoded->aal));
        }

        return $decoded->aal;
    }

    /**
     * Extract app_metadata claims from JWT.
     *
     * @return array{bool, ?string, ?string} [isApproved, roleName, departmentsSummary]
     *
     * @throws InvalidJwtClaimsException
     */
    private static function extractAppMetadata(stdClass $decoded): array
    {
        if (!isset($decoded->app_metadata)) {
            return [false, null, null];
        }

        if (!$decoded->app_metadata instanceof stdClass) {
            throw InvalidJwtClaimsException::invalidType(
                'app_metadata',
                'object',
                \gettype($decoded->app_metadata),
            );
        }

        $appMetadata = $decoded->app_metadata;

        return [
            isset($appMetadata->is_approved) && $appMetadata->is_approved === true,
            self::extractOptionalString($appMetadata, 'role_name', 'app_metadata.role_name'),
            self::extractOptionalString($appMetadata, 'departments_summary', 'app_metadata.departments_summary'),
        ];
    }

    /**
     * Extract an optional string property from an object.
     *
     * @throws InvalidJwtClaimsException
     */
    private static function extractOptionalString(stdClass $object, string $property, string $claimPath): ?string
    {
        if (!isset($object->{$property})) {
            return null;
        }

        if (!\is_string($object->{$property})) {
            throw InvalidJwtClaimsException::invalidType($claimPath, 'string', \gettype($object->{$property}));
        }

        return $object->{$property};
    }

    /**
     * Check if MFA has been verified (AAL2).
     */
    public function isMfaVerified(): bool
    {
        return $this->aal === 'aal2';
    }

    /**
     * Convert to domain AuthenticatedUser value object.
     */
    public function toAuthenticatedUser(): AuthenticatedUser
    {
        return new AuthenticatedUser(
            id: $this->userId,
            email: $this->email,
            isApproved: $this->isApproved,
            roleName: $this->roleName,
            departmentsSummary: $this->departmentsSummary,
        );
    }

    /**
     * @throws InvalidJwtClaimsException
     */
    private static function validateRequiredClaim(stdClass $decoded, string $claim, string $expectedType): void
    {
        if (!isset($decoded->{$claim})) {
            throw InvalidJwtClaimsException::missingClaim($claim);
        }

        $actualType = \gettype($decoded->{$claim});
        if ($actualType !== $expectedType) {
            throw InvalidJwtClaimsException::invalidType($claim, $expectedType, $actualType);
        }

        // For strings, also check not empty
        if ($expectedType === 'string' && $decoded->{$claim} === '') {
            throw InvalidJwtClaimsException::emptyClaim($claim);
        }
    }
}
