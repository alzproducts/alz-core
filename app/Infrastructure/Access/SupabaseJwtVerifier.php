<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\Contracts\Access\AccessTokenVerifierInterface;
use App\Domain\Access\Exceptions\InvalidAccessTokenException;
use App\Domain\Access\ValueObjects\AccessTokenClaims;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use stdClass;
use Throwable;

/**
 * Verifies Supabase Auth ES256 access tokens against the project's JWKS.
 */
final readonly class SupabaseJwtVerifier implements AccessTokenVerifierInterface
{
    private const string ALGORITHM = 'ES256';

    private const string AUDIENCE = 'authenticated';

    public function __construct(
        private SupabaseAuthConfig $config,
        private SupabaseJwksKeySet $keySet,
    ) {}

    /**
     * @throws InvalidAccessTokenException When the token is malformed, unverifiable or carries invalid claims
     * @throws ExternalServiceUnavailableException When the signing keys cannot be fetched
     */
    public function verify(string $token): AccessTokenClaims
    {
        // intentional: header checks run before any JWKS access so kid-less or non-ES256 tokens never trigger a fetch
        $kid = self::keyIdFromHeader($token);

        $decoded = self::decode($token, $this->keySet->keyFor($kid));
        $this->assertRegisteredClaims($decoded);

        return SupabaseJwtClaimsParser::parse($decoded);
    }

    /**
     * @throws InvalidAccessTokenException
     */
    private static function keyIdFromHeader(string $token): string
    {
        $header = self::decodeHeader($token);

        if (($header->alg ?? null) !== self::ALGORITHM) {
            throw InvalidAccessTokenException::unsupportedAlgorithm();
        }

        $kid = $header->kid ?? null;
        if (!\is_string($kid) || $kid === '') {
            throw InvalidAccessTokenException::missingKeyId();
        }

        return $kid;
    }

    /**
     * @throws InvalidAccessTokenException
     */
    private static function decodeHeader(string $token): stdClass
    {
        $segments = \explode('.', $token);
        if (\count($segments) !== 3) {
            throw InvalidAccessTokenException::malformedToken();
        }

        try {
            $header = JWT::jsonDecode(JWT::urlsafeB64Decode($segments[0]));
        } catch (Throwable $e) {
            throw InvalidAccessTokenException::malformedToken($e);
        }

        if (!$header instanceof stdClass) {
            throw InvalidAccessTokenException::malformedToken();
        }

        return $header;
    }

    /**
     * php-jwt raises its own exceptions, \DomainException, and PHP Errors from a malformed signature.
     *
     * @throws InvalidAccessTokenException
     */
    private static function decode(string $token, Key $key): stdClass
    {
        try {
            return JWT::decode($token, $key);
        } catch (Throwable $e) { // @ignoreException - every decode failure is a rejected token
            throw InvalidAccessTokenException::verificationFailed($e);
        }
    }

    /**
     * @throws InvalidAccessTokenException
     */
    private function assertRegisteredClaims(stdClass $decoded): void
    {
        if (!isset($decoded->exp)) {
            throw InvalidAccessTokenException::missingExpiry();
        }

        if (($decoded->iss ?? null) !== $this->config->issuer) {
            throw InvalidAccessTokenException::issuerMismatch();
        }

        if (!self::hasExpectedAudience($decoded->aud ?? null)) {
            throw InvalidAccessTokenException::audienceMismatch();
        }
    }

    private static function hasExpectedAudience(mixed $audience): bool
    {
        return $audience === self::AUDIENCE
            || (\is_array($audience) && \in_array(self::AUDIENCE, $audience, true));
    }
}
