<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Application\Contracts\LockableCacheInterface;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidApiResponseException;
use DateTimeImmutable;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manages Bing Ads session lifecycle: OAuth token refresh, caching, and atomic locking.
 *
 * Key responsibilities:
 * - Cache lookup and storage via LockableCache (graceful degradation)
 * - Atomic locks for concurrent authentication prevention (thundering herd)
 * - Microsoft OAuth2 token refresh calls
 * - TTL management with configurable buffer
 *
 * Unlike Google Ads SDK which handles token refresh internally, the Bing Ads PHP SDK
 * requires manual OAuth token management.
 *
 * @template-pattern Session Manager
 */
final class BingAdsSessionManager
{
    private const string SERVICE_NAME = 'Bing Ads';
    private const string CACHE_KEY = 'bingads:session';
    private const int DEFAULT_TTL_SECONDS = 3600;
    private const int REQUEST_TIMEOUT_SECONDS = 30;
    private const int TTL_BUFFER_SECONDS = 60;

    public function __construct(
        private readonly BingAdsConfig $config,
        private readonly LockableCacheInterface $cache,
    ) {}

    /**
     * Get a valid session (cache-first, refreshes token if needed).
     *
     * Uses LockableCache for:
     * - Thundering herd prevention (atomic locks)
     * - Graceful degradation on cache failures
     * - Double-check pattern after lock acquisition
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When OAuth endpoint unavailable
     * @throws InvalidApiResponseException When OAuth response missing required fields
     */
    public function getSession(): BingAdsSession
    {
        /** @var BingAdsSession */
        return $this->cache->remember(
            self::CACHE_KEY,
            fn(): BingAdsSession => $this->refreshToken(),
            self::DEFAULT_TTL_SECONDS,
            static fn(mixed $cached): bool => ($cached instanceof BingAdsSession) && !$cached->isExpired(),
        );
    }

    /**
     * Invalidate the cached session (called by transport on auth failure).
     */
    public function invalidate(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Call Microsoft OAuth2 token endpoint.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When OAuth endpoint unavailable
     * @throws InvalidApiResponseException When OAuth response missing required fields
     */
    private function refreshToken(): BingAdsSession
    {
        try {
            $response = Http::timeout(self::REQUEST_TIMEOUT_SECONDS)
                ->send('POST', BingAdsConfig::TOKEN_URL, [
                    'form_params' => [
                        'client_id' => $this->config->clientId,
                        'client_secret' => $this->config->clientSecret,
                        'refresh_token' => $this->config->refreshToken,
                        'grant_type' => 'refresh_token',
                        'scope' => 'https://ads.microsoft.com/msads.manage offline_access',
                    ],
                ])
                ->throw();

            /** @var array<string, mixed> $data */
            $data = $response->json();

            return self::createSessionFromOAuth($data);
        } catch (RequestException $e) {
            $this->handleOAuthRequestException($e);
        } catch (ConnectionException $e) {
            Log::error(self::SERVICE_NAME . ' OAuth connection failed', [
                'error' => $e->getMessage(),
            ]);

            throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
        } catch (Exception $e) {
            // Catch-all for unexpected exceptions (SSL errors, Guzzle internals, etc.)
            Log::error(self::SERVICE_NAME . ' OAuth unexpected error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
        }
    }

    /**
     * Parse OAuth response and create session with TTL buffer.
     *
     * OAuth response parsing and TTL policy belong here (at the boundary),
     * not in the BingAdsSession value object which stays pure.
     *
     * @param array<string, mixed> $response OAuth token response
     *
     * @throws InvalidApiResponseException When response missing required fields
     */
    private static function createSessionFromOAuth(array $response): BingAdsSession
    {
        $accessToken = $response['access_token'] ?? null;
        $expiresIn = $response['expires_in'] ?? null;

        if (!\is_string($accessToken) || ($accessToken === '')) {
            throw new InvalidApiResponseException(
                self::SERVICE_NAME,
                'OAuth response missing valid access_token',
            );
        }

        if (!\is_int($expiresIn) || ($expiresIn <= 0)) {
            throw new InvalidApiResponseException(
                self::SERVICE_NAME,
                'OAuth response missing valid expires_in',
            );
        }

        $effectiveTtl = \max(1, $expiresIn - self::TTL_BUFFER_SECONDS);
        $expiresAt = new DateTimeImmutable("+{$effectiveTtl} seconds");

        return new BingAdsSession($accessToken, $expiresAt);
    }

    /**
     * Handle OAuth token request failures.
     *
     * @throws AuthenticationExpiredException When credentials are invalid (400/401/403)
     * @throws ExternalServiceUnavailableException When OAuth endpoint unavailable (other errors)
     */
    private function handleOAuthRequestException(RequestException $e): never
    {
        $status = $e->response->status();

        // OAuth2 returns 400 for invalid_grant (expired refresh token)
        if (\in_array($status, [400, 401, 403], true)) {
            Log::error(self::SERVICE_NAME . ' OAuth authentication failed', [
                'status' => $status,
                'error' => $e->getMessage(),
                'response' => $e->response->json(),
            ]);

            throw new AuthenticationExpiredException(
                self::SERVICE_NAME,
                'Invalid credentials or refresh token expired. Re-authorize the application.',
                $e,
            );
        }

        Log::error(self::SERVICE_NAME . ' OAuth endpoint error', [
            'status' => $status,
            'error' => $e->getMessage(),
        ]);

        throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}
