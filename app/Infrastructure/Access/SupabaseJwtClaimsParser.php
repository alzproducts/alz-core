<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Domain\Access\Exceptions\InvalidAccessTokenException;
use App\Domain\Access\ValueObjects\AccessTokenClaims;
use Illuminate\Support\Str;
use stdClass;

/**
 * Validates and extracts claims from a verified Supabase JWT payload.
 *
 * Expected shape:
 * {
 *   "sub": "uuid",           // Required: User ID
 *   "email": "user@...",     // Required: User email
 *   "aal": "aal1|aal2",      // Optional: Authentication Assurance Level, defaults to aal1
 *   "app_metadata": {        // Optional: Custom claims from Edge Function
 *     "is_approved": bool,
 *     "role_name": string,
 *     "departments_summary": string | string[]  // Normalized to array
 *   }
 * }
 */
final class SupabaseJwtClaimsParser
{
    /**
     * @throws InvalidAccessTokenException When required claims are missing or malformed
     */
    public static function parse(stdClass $decoded): AccessTokenClaims
    {
        $userId = self::extractUserId($decoded);
        $email = self::extractEmail($decoded);
        [$isApproved, $roleName, $departments] = self::extractAppMetadata($decoded);

        return new AccessTokenClaims(
            userId: $userId,
            email: $email,
            aal: self::extractAal($decoded),
            isApproved: $isApproved,
            roleName: $roleName,
            departments: $departments,
        );
    }

    /**
     * @throws InvalidAccessTokenException
     */
    private static function extractUserId(stdClass $decoded): string
    {
        $userId = self::requiredString($decoded, 'sub');
        if (!Str::isUuid($userId)) {
            throw InvalidAccessTokenException::invalidClaimValue('sub', 'uuid');
        }

        return $userId;
    }

    /**
     * @throws InvalidAccessTokenException
     */
    private static function extractEmail(stdClass $decoded): string
    {
        $email = self::requiredString($decoded, 'email');
        if (\filter_var($email, \FILTER_VALIDATE_EMAIL) === false) {
            throw InvalidAccessTokenException::invalidClaimValue('email', 'email address');
        }

        return $email;
    }

    /**
     * @throws InvalidAccessTokenException
     */
    private static function requiredString(stdClass $decoded, string $claim): string
    {
        if (!isset($decoded->{$claim})) {
            throw InvalidAccessTokenException::missingClaim($claim);
        }

        $value = $decoded->{$claim};
        if (!\is_string($value)) {
            throw InvalidAccessTokenException::invalidClaimType($claim, 'string', \gettype($value));
        }

        if ($value === '') {
            throw InvalidAccessTokenException::emptyClaim($claim);
        }

        return $value;
    }

    /**
     * Password-only sessions carry no `aal`; treat them as aal1.
     *
     * @throws InvalidAccessTokenException
     */
    private static function extractAal(stdClass $decoded): string
    {
        if (!isset($decoded->aal)) {
            return 'aal1';
        }

        if (!\is_string($decoded->aal)) {
            throw InvalidAccessTokenException::invalidClaimType('aal', 'string', \gettype($decoded->aal));
        }

        return $decoded->aal;
    }

    /**
     * @return array{bool, ?string, list<string>|null} [isApproved, roleName, departments]
     *
     * @throws InvalidAccessTokenException
     */
    private static function extractAppMetadata(stdClass $decoded): array
    {
        if (!isset($decoded->app_metadata)) {
            return [false, null, null];
        }

        $appMetadata = $decoded->app_metadata;
        if (!$appMetadata instanceof stdClass) {
            throw InvalidAccessTokenException::invalidClaimType('app_metadata', 'object', \gettype($appMetadata));
        }

        return [
            isset($appMetadata->is_approved) && $appMetadata->is_approved === true,
            self::extractOptionalString($appMetadata, 'role_name', 'app_metadata.role_name'),
            self::extractDepartments($appMetadata),
        ];
    }

    /**
     * @throws InvalidAccessTokenException
     */
    private static function extractOptionalString(stdClass $object, string $property, string $claimPath): ?string
    {
        if (!isset($object->{$property})) {
            return null;
        }

        if (!\is_string($object->{$property})) {
            throw InvalidAccessTokenException::invalidClaimType($claimPath, 'string', \gettype($object->{$property}));
        }

        return $object->{$property};
    }

    /**
     * Accepts either a comma-separated string or an array of strings.
     *
     * @return list<string>|null
     *
     * @throws InvalidAccessTokenException
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

        throw InvalidAccessTokenException::invalidClaimType(
            'app_metadata.departments_summary',
            'string or array of strings',
            \gettype($value),
        );
    }

    /**
     * @param array<mixed> $value
     *
     * @return list<string>|null
     *
     * @throws InvalidAccessTokenException
     */
    private static function validateStringArray(array $value, string $claimPath): ?array
    {
        if ($value === []) {
            return null;
        }

        foreach ($value as $element) {
            if (!\is_string($element)) {
                throw InvalidAccessTokenException::invalidClaimType(
                    $claimPath,
                    'string or array of strings',
                    'array with non-string elements',
                );
            }
        }

        /** @var list<string> $value */
        return $value;
    }
}
