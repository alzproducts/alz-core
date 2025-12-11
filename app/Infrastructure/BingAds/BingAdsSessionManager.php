<?php

declare(strict_types=1);

namespace App\Infrastructure\BingAds;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use Exception;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manages Bing Ads session lifecycle: OAuth token refresh, caching, and atomic locking.
 *
 * Key responsibilities:
 * - Cache lookup and storage (Redis)
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
    private const string LOCK_KEY = 'bingads:session:lock';
    private const int LOCK_TIMEOUT_SECONDS = 30;
    private const int LOCK_WAIT_SECONDS = 10;
    private const int REQUEST_TIMEOUT_SECONDS = 30;
    private const int TTL_BUFFER_SECONDS = 60;

    public function __construct(
        private readonly BingAdsConfig $config,
        private readonly CacheManager $cache,
    ) {}

    /**
     * Get a valid session (cache-first, refreshes token if needed).
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When OAuth endpoint unavailable
     */
    public function getSession(): BingAdsSession
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (($cached instanceof BingAdsSession) && !$cached->isExpired()) {
            return $cached;
        }

        return $this->refreshWithLock();
    }

    /**
     * Invalidate the cached session (called by transport on auth failure).
     */
    public function invalidate(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Refresh OAuth token with atomic lock to prevent thundering herd.
     *
     * When multiple requests hit an expired session simultaneously,
     * only one will perform token refresh while others wait.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When OAuth endpoint unavailable
     */
    private function refreshWithLock(): BingAdsSession
    {
        $lock = $this->cache->lock(self::LOCK_KEY, self::LOCK_TIMEOUT_SECONDS);

        try {
            $acquired = $lock->block(self::LOCK_WAIT_SECONDS);

            if ($acquired !== true) {
                throw new LockTimeoutException('Failed to acquire Bing Ads session lock');
            }

            try {
                // Double-check after acquiring lock (another request may have refreshed)
                $cached = $this->cache->get(self::CACHE_KEY);

                if (($cached instanceof BingAdsSession) && !$cached->isExpired()) {
                    return $cached;
                }

                return $this->refreshToken();
            } finally {
                $lock->release();
            }
        } catch (LockTimeoutException) {
            Log::warning(self::SERVICE_NAME . ' session lock timeout, attempting direct refresh', [
                'lock_wait_seconds' => self::LOCK_WAIT_SECONDS,
            ]);

            // Fallback: refresh directly if lock acquisition fails
            return $this->refreshToken();
        }
    }

    /**
     * Call Microsoft OAuth2 token endpoint and cache the session.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When OAuth endpoint unavailable
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

            $session = BingAdsSession::fromOAuthResponse($data, self::TTL_BUFFER_SECONDS);

            $this->cacheSession($session);

            return $session;
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
     * Cache the session with calculated TTL.
     */
    private function cacheSession(BingAdsSession $session): void
    {
        $ttl = $session->expiresAt->getTimestamp() - \time();
        $this->cache->put(self::CACHE_KEY, $session, \max(1, $ttl));
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
