<?php

declare(strict_types=1);

namespace App\Application\Contracts\Access;

use App\Domain\Access\Exceptions\InvalidAccessTokenException;
use App\Domain\Access\ValueObjects\AccessTokenClaims;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;

interface AccessTokenVerifierInterface
{
    /**
     * Verify a raw bearer token and return its claims.
     *
     * @throws InvalidAccessTokenException When the token is malformed, unverifiable or carries invalid claims
     * @throws ExternalServiceUnavailableException When the signing keys cannot be fetched
     */
    public function verify(string $token): AccessTokenClaims;
}
