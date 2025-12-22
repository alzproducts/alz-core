<?php

declare(strict_types=1);

namespace App\Presentation\Http\Auth\Exceptions;

use RuntimeException;

/**
 * Thrown when a Supabase JWT has missing or malformed claims.
 *
 * This indicates either:
 * 1. Token tampering (security concern)
 * 2. Supabase Edge Function misconfiguration
 * 3. Breaking change in Supabase JWT structure
 *
 * All cases should be logged and result in authentication failure.
 */
final class InvalidJwtClaimsException extends RuntimeException
{
    public function __construct(
        public readonly string $claim,
        public readonly string $reason,
    ) {
        parent::__construct("Invalid JWT: {$reason} (claim: {$claim})");
    }

    public static function missingClaim(string $claim): self
    {
        return new self($claim, "required claim '{$claim}' is missing");
    }

    public static function invalidType(string $claim, string $expected, string $actual): self
    {
        return new self($claim, "expected {$expected}, got {$actual}");
    }

    public static function emptyClaim(string $claim): self
    {
        return new self($claim, "claim '{$claim}' cannot be empty");
    }
}
