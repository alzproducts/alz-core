<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use DateMalformedStringException;
use Illuminate\Cache\CacheManager;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manages Linnworks session lifecycle: authentication, caching, and refresh.
 *
 * Key responsibilities:
 * - Cache lookup and storage (Redis)
 * - Atomic locks for concurrent authentication prevention (thundering herd)
 * - Auth endpoint calls
 * - TTL management with configurable buffer
 *
 * @template-pattern Session Manager
 */
final class LinnworksSessionManager
{
    private const string SERVICE_NAME = 'Linnworks';
    private const string CACHE_KEY = 'linnworks:session';
    private const string LOCK_KEY = 'linnworks:session:lock';
    private const int LOCK_TIMEOUT_SECONDS = 30;
    private const int LOCK_WAIT_SECONDS = 10;

    public function __construct(
        private readonly LinnworksConfig $config,
        private readonly CacheManager $cache,
    ) {}

    /**
     * Get a valid session (cache-first, authenticates if needed).
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException|DateMalformedStringException When auth endpoint unavailable
     */
    public function getSession(): LinnworksSession
    {
        $cached = $this->cache->get(self::CACHE_KEY);

        if (($cached instanceof LinnworksSession) && !$cached->isExpired()) {
            return $cached;
        }

        return $this->authenticateWithLock();
    }

    /**
     * Invalidate the cached session (called by transport on 401).
     */
    public function invalidate(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Authenticate with atomic lock to prevent thundering herd.
     *
     * When multiple requests hit an expired session simultaneously,
     * only one will perform authentication while others wait.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException|DateMalformedStringException When auth endpoint unavailable
     */
    private function authenticateWithLock(): LinnworksSession
    {
        $lock = $this->cache->lock(self::LOCK_KEY, self::LOCK_TIMEOUT_SECONDS);

        try {
            $acquired = $lock->block(self::LOCK_WAIT_SECONDS);

            if ($acquired !== true) {
                throw new LockTimeoutException('Failed to acquire Linnworks session lock');
            }

            try {
                // Double-check after acquiring lock (another request may have authenticated)
                $cached = $this->cache->get(self::CACHE_KEY);

                if (($cached instanceof LinnworksSession) && !$cached->isExpired()) {
                    return $cached;
                }

                return $this->authenticate();
            } finally {
                $lock->release();
            }
        } catch (LockTimeoutException) {
            Log::warning(self::SERVICE_NAME . ' session lock timeout, attempting direct auth', [
                'lock_wait_seconds' => self::LOCK_WAIT_SECONDS,
            ]);

            // Fallback: authenticate directly if lock acquisition fails
            return $this->authenticate();
        }
    }

    /**
     * Call Linnworks auth endpoint and cache the session.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException|DateMalformedStringException When auth endpoint unavailable
     */
    private function authenticate(): LinnworksSession
    {
        try {
            $response = Http::timeout($this->config->timeout)
                ->send('POST', LinnworksConfig::AUTH_URL, [
                    'form_params' => [
                        'applicationId' => $this->config->applicationId,
                        'applicationSecret' => $this->config->applicationSecret,
                        'token' => $this->config->installationToken,
                    ],
                ])
                ->throw();

            /** @var array<string, mixed> $data */
            $data = $response->json();

            $session = LinnworksSession::fromAuthResponse(
                $data,
                $this->config->cacheTtlBuffer,
            );

            $this->cacheSession($session);

            return $session;
        } catch (RequestException $e) {
            return $this->handleAuthRequestException($e);
        } catch (ConnectionException $e) {
            Log::error(self::SERVICE_NAME . ' auth connection failed', [
                'error' => $e->getMessage(),
            ]);

            throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
        }
    }

    /**
     * Cache the session with calculated TTL.
     */
    private function cacheSession(LinnworksSession $session): void
    {
        $ttl = $session->expiresAt->getTimestamp() - \time();
        $this->cache->put(self::CACHE_KEY, $session, \max(1, $ttl));
    }

    /**
     * Handle authentication request failures.
     *
     *
     * @throws AuthenticationExpiredException When credentials are invalid (401/403)
     * @throws ExternalServiceUnavailableException When auth endpoint unavailable (other errors)
     */
    private function handleAuthRequestException(RequestException $e): never
    {
        $status = $e->response->status();

        if (($status === 401) || ($status === 403)) {
            Log::error(self::SERVICE_NAME . ' authentication failed', [
                'status' => $status,
                'error' => $e->getMessage(),
            ]);

            throw new AuthenticationExpiredException(
                self::SERVICE_NAME,
                'Invalid credentials or application not authorized',
                $e,
            );
        }

        Log::error(self::SERVICE_NAME . ' auth endpoint error', [
            'status' => $status,
            'error' => $e->getMessage(),
        ]);

        throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}
