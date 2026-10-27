<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Application\Contracts\LockableCacheInterface;
use App\Application\Contracts\ResilientCacheInterface;
use App\Domain\Access\Exceptions\InvalidAccessTokenException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use Closure;
use Firebase\JWT\JWK;
use Firebase\JWT\Key;
use Illuminate\Cache\CacheManager;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Supabase ES256 signing keys, cached by kid.
 */
final readonly class SupabaseJwksKeySet
{
    private const string KEYS_CACHE_KEY = 'supabase_jwks:keys';

    private const string REFETCH_COOLDOWN_KEY = 'supabase_jwks:refetch_cooldown';

    private const string FETCH_FAILED_KEY = 'supabase_jwks:fetch_failed';

    // Supabase requires JWKS caches to expire within 10 minutes.
    private const int KEYS_TTL_SECONDS = 600;

    private const int REFETCH_COOLDOWN_SECONDS = 60;

    private const int FETCH_FAILED_TTL_SECONDS = 10;

    private const string ALGORITHM = 'ES256';

    public function __construct(
        private SupabaseJwksHttpTransport $transport,
        private LockableCacheInterface $keyCache,
        private ResilientCacheInterface $markerCache,
        private CacheManager $cache,
    ) {}

    /**
     * @throws InvalidAccessTokenException When no usable key has this kid
     * @throws ExternalServiceUnavailableException When the key set cannot be fetched
     */
    public function keyFor(string $kid): Key
    {
        $keys = $this->cachedKeys(static fn(mixed $cached): bool => \is_array($cached) && $cached !== []);
        if (isset($keys[$kid])) {
            return self::toKey($keys[$kid]);
        }

        return $this->refetchedKeyFor($kid);
    }

    /**
     * @throws InvalidAccessTokenException When no usable key has this kid
     * @throws ExternalServiceUnavailableException When the key set cannot be fetched
     */
    private function refetchedKeyFor(string $kid): Key
    {
        if ($this->fetchRecentlyFailed()) {
            throw new ExternalServiceUnavailableException(SupabaseJwksHttpTransport::SERVICE_NAME);
        }

        if (!$this->acquireRefetchCooldown()) {
            throw InvalidAccessTokenException::unknownKeyId();
        }

        $keys = $this->cachedKeys(static fn(mixed $cached): bool => \is_array($cached) && isset($cached[$kid]));
        if (isset($keys[$kid])) {
            return self::toKey($keys[$kid]);
        }

        throw InvalidAccessTokenException::unknownKeyId();
    }

    /**
     * @param Closure(mixed): bool $validator
     *
     * @return array<string, array<string, string>>
     *
     * @throws ExternalServiceUnavailableException
     */
    private function cachedKeys(Closure $validator): array
    {
        return $this->keyCache->remember(
            self::KEYS_CACHE_KEY,
            fn(): array => $this->fetchUsableKeys(),
            self::KEYS_TTL_SECONDS,
            $validator,
        );
    }

    /**
     * @return array<string, array<string, string>>
     *
     * @throws ExternalServiceUnavailableException
     */
    private function fetchUsableKeys(): array
    {
        if ($this->fetchRecentlyFailed()) {
            throw new ExternalServiceUnavailableException(SupabaseJwksHttpTransport::SERVICE_NAME);
        }

        try {
            return $this->fetchAndFilter();
        } catch (ExternalServiceUnavailableException $e) {
            $this->markerCache->put(self::FETCH_FAILED_KEY, true, self::FETCH_FAILED_TTL_SECONDS);

            throw $e;
        }
    }

    /**
     * @return array<string, array<string, string>>
     *
     * @throws ExternalServiceUnavailableException
     */
    private function fetchAndFilter(): array
    {
        $keys = self::filterUsableKeys($this->transport->fetchKeys());

        if ($keys === []) {
            Log::error('Supabase JWKS contains no usable ES256 signing key');

            throw new ExternalServiceUnavailableException(SupabaseJwksHttpTransport::SERVICE_NAME);
        }

        return $keys;
    }

    private function fetchRecentlyFailed(): bool
    {
        return $this->markerCache->get(self::FETCH_FAILED_KEY) === true;
    }

    private function acquireRefetchCooldown(): bool
    {
        try {
            return $this->cache->store()->add(self::REFETCH_COOLDOWN_KEY, true, self::REFETCH_COOLDOWN_SECONDS);
        } catch (Throwable $e) { // @ignoreException - graceful degradation: no cooldown means no refetch
            Log::warning('Supabase JWKS refetch cooldown unavailable', [
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /**
     * @param array<string, string> $jwk
     *
     * @throws InvalidAccessTokenException
     */
    private static function toKey(array $jwk): Key
    {
        try {
            $key = JWK::parseKey($jwk, self::ALGORITHM);
        } catch (Throwable) { // @ignoreException - cached entries were parsed before caching; a failure here means an unusable key
            $key = null;
        }

        return $key ?? throw InvalidAccessTokenException::unknownKeyId();
    }

    /**
     * @param list<mixed> $entries
     *
     * @return array<string, array<string, string>>
     */
    private static function filterUsableKeys(array $entries): array
    {
        $usable = [];
        foreach ($entries as $entry) {
            $jwk = self::usableJwk($entry);
            if ($jwk !== null) {
                $usable[$jwk['kid']] = $jwk;
            }
        }

        return $usable;
    }

    /**
     * @return array{kty: string, crv: string, alg: string, kid: string, x: string, y: string}|null
     */
    private static function usableJwk(mixed $entry): ?array
    {
        if (!\is_array($entry) || !self::isEs256SigningKey($entry)) {
            return null;
        }

        $kid = $entry['kid'] ?? null;
        $x = $entry['x'] ?? null;
        $y = $entry['y'] ?? null;
        if (!self::isNonEmptyString($kid) || !self::isNonEmptyString($x) || !self::isNonEmptyString($y)) {
            return null;
        }

        $jwk = ['kty' => 'EC', 'crv' => 'P-256', 'alg' => self::ALGORITHM, 'kid' => $kid, 'x' => $x, 'y' => $y];

        return self::isValidPublicKey($jwk) ? $jwk : null;
    }

    /**
     * @param array<mixed> $entry
     */
    private static function isEs256SigningKey(array $entry): bool
    {
        return ($entry['kty'] ?? null) === 'EC'
            && ($entry['crv'] ?? null) === 'P-256'
            && ($entry['alg'] ?? self::ALGORITHM) === self::ALGORITHM
            && ($entry['use'] ?? 'sig') === 'sig';
    }

    /**
     * @phpstan-assert-if-true non-empty-string $value
     */
    private static function isNonEmptyString(mixed $value): bool
    {
        return \is_string($value) && $value !== '';
    }

    /**
     * php-jwt builds the EC PEM without OpenSSL, so an off-curve point would otherwise
     * surface on every request as a signature failure instead of being dropped here.
     *
     * @param array<string, string> $jwk
     */
    private static function isValidPublicKey(array $jwk): bool
    {
        try {
            $key = JWK::parseKey($jwk, self::ALGORITHM);
        } catch (Throwable) { // @ignoreException - an unparsable key is filtered out
            return false;
        }

        return $key !== null && \openssl_pkey_get_public($key->getKeyMaterial()) !== false;
    }
}
