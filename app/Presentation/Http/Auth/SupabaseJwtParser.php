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
 *     "departments_summary": string | string[]  // Normalized to array internally
 *   }
 * }
 */
final readonly class SupabaseJwtParser
{
    /**
     * @param list<string>|null $departments
     */
    private function __construct(
        public string $userId,
        public string $email,
        public string $aal,
        public bool $isApproved,
        public ?string $roleName,
        public ?array $departments,
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
        self::validateRequiredClaim($decoded, 'sub', 'string');
        self::validateRequiredClaim($decoded, 'email', 'string');
        /** @var string $userId */
        $userId = $decoded->sub;
        /** @var string $email */
        $email = $decoded->email;
        $aal = self::extractAal($decoded);
        [$isApproved, $roleName, $departments] = self::extractAppMetadata($decoded);

        return new self(
            userId: $userId,
            email: $email,
            aal: $aal,
            isApproved: $isApproved,
            roleName: $roleName,
            departments: $departments,
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
     * @return array{bool, ?string, list<string>|null} [isApproved, roleName, departments]
     *
     * @throws InvalidJwtClaimsException
     */
    private static function extractAppMetadata(stdClass $decoded): array
    {
        if (!isset($decoded->app_metadata)) {
            return [false, null, null];
        }
        $appMetadata = self::validateObjectClaim($decoded->app_metadata, 'app_metadata');

        return [
            isset($appMetadata->is_approved) && $appMetadata->is_approved === true,
            self::extractOptionalString($appMetadata, 'role_name', 'app_metadata.role_name'),
            self::extractDepartments($appMetadata),
        ];
    }

    /**
     * @throws InvalidJwtClaimsException
     */
    private static function validateObjectClaim(mixed $value, string $claimPath): stdClass
    {
        if (!$value instanceof stdClass) {
            throw InvalidJwtClaimsException::invalidType($claimPath, 'object', \gettype($value));
        }

        return $value;
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
     * Extract departments from app_metadata.
     * Accepts either a comma-separated string or an array of strings.
     *
     * @return list<string>|null
     *
     * @throws InvalidJwtClaimsException
     */
    private static function extractDepartments(stdClass $appMetadata): ?array
    {
        if (!isset($appMetadata->departments_summary)) {
            return null;
        }
        $value = $appMetadata->departments_summary;
        if (\is_string($value)) {
            return $value === '' ? null : \explode(',', $value);
        }
        if (\is_array($value)) {
            return self::validateStringArray($value, 'app_metadata.departments_summary');
        }

        throw InvalidJwtClaimsException::invalidType(
            'app_metadata.departments_summary',
            'string or array of strings',
            \gettype($value),
        );
    }

    /**
     * Validate and normalize an array to ensure all elements are strings.
     *
     * @param array<mixed> $value
     *
     * @return list<string>|null
     *
     * @throws InvalidJwtClaimsException
     */
    private static function validateStringArray(array $value, string $claimPath): ?array
    {
        if ($value === []) {
            return null;
        }

        foreach ($value as $element) {
            if (!\is_string($element)) {
                throw InvalidJwtClaimsException::invalidType(
                    $claimPath,
                    'string or array of strings',
                    'array with non-string elements',
                );
            }
        }

        /** @var list<string> $value */
        return $value;
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
            departments: $this->departments,
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
