<?php

declare(strict_types=1);

namespace App\Domain\Access\Exceptions;

use App\Domain\Exceptions\DomainException;
use Override;
use Throwable;

/**
 * Thrown for every rejected bearer token: structure, signature, registered claims and identity claims.
 */
final class InvalidAccessTokenException extends DomainException
{
    public function __construct(
        public readonly string $reason,
        public readonly string $claim = '',
        public readonly string $detail = '',
        ?Throwable $previous = null,
    ) {
        parent::__construct('Invalid access token', 0, $previous);
    }

    public static function malformedToken(): self
    {
        return new self('malformed_token');
    }

    public static function unsupportedAlgorithm(): self
    {
        return new self('unsupported_algorithm');
    }

    public static function missingKeyId(): self
    {
        return new self('missing_key_id');
    }

    public static function unknownKeyId(): self
    {
        return new self('unknown_key_id');
    }

    public static function verificationFailed(Throwable $previous): self
    {
        return new self('verification_failed', previous: $previous);
    }

    public static function missingExpiry(): self
    {
        return new self('missing_expiry');
    }

    public static function issuerMismatch(): self
    {
        return new self('issuer_mismatch');
    }

    public static function audienceMismatch(): self
    {
        return new self('audience_mismatch');
    }

    public static function missingClaim(string $claim): self
    {
        return new self('missing_claim', $claim);
    }

    public static function invalidClaimType(string $claim, string $expected, string $actual): self
    {
        return new self('invalid_claim_type', $claim, "expected {$expected}, got {$actual}");
    }

    public static function emptyClaim(string $claim): self
    {
        return new self('empty_claim', $claim);
    }

    public static function invalidClaimValue(string $claim, string $expected): self
    {
        return new self('invalid_claim_value', $claim, "expected {$expected}");
    }

    /**
     * @return array<string, string>
     */
    #[Override]
    public function context(): array
    {
        $context = \array_filter([
            'reason' => $this->reason,
            'claim' => $this->claim,
            'detail' => $this->detail,
        ], static fn(string $value): bool => $value !== '');

        $previous = $this->getPrevious();
        if ($previous !== null) {
            $context['cause'] = $previous::class;
            $context['cause_message'] = $previous->getMessage();
        }

        return $context;
    }
}
