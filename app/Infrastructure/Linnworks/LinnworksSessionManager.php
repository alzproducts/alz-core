<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Application\Contracts\LockableCacheInterface;
use App\Domain\Exceptions\AuthenticationExpiredException;
use App\Domain\Exceptions\ExternalServiceUnavailableException;
use DateMalformedStringException;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Manages Linnworks session lifecycle: authentication, caching, and refresh.
 *
 * Key responsibilities:
 * - Cache lookup and storage via LockableCache (graceful degradation)
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
    private const int DEFAULT_TTL_SECONDS = 3600;

    public function __construct(
        private readonly LinnworksConfig $config,
        private readonly LockableCacheInterface $cache,
    ) {}

    /**
     * Get a valid session (cache-first, authenticates if needed).
     * Uses LockableCache for:
     * - Thundering herd prevention (atomic locks)
     * - Graceful degradation on cache failures
     * - Double-check pattern after lock acquisition
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When auth endpoint unavailable
     * @throws DateMalformedStringException When date parsing fails
     * @noinspection PhpDocRedundantThrowsInspection*/
    public function getSession(): LinnworksSession
    {
        /** @var LinnworksSession */
        return $this->cache->remember(
            self::CACHE_KEY,
            fn(): LinnworksSession => $this->authenticate(),
            self::DEFAULT_TTL_SECONDS,
            static fn(mixed $cached): bool => ($cached instanceof LinnworksSession) && !$cached->isExpired(),
        );
    }

    /**
     * Invalidate the cached session (called by transport on 401).
     */
    public function invalidate(): void
    {
        $this->cache->forget(self::CACHE_KEY);
    }

    /**
     * Call Linnworks auth endpoint.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When auth endpoint unavailable
     * @throws DateMalformedStringException When date parsing fails
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

            return LinnworksSession::fromAuthResponse(
                $data,
                $this->config->cacheTtlBuffer,
            );
        } catch (RequestException $e) {
            $this->handleAuthRequestException($e);
        } catch (ConnectionException $e) {
            Log::error(self::SERVICE_NAME . ' auth connection failed', [
                'error' => $e->getMessage(),
            ]);

            throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
        } catch (DateMalformedStringException $e) {
            // Let this propagate - transport layer translates to InvalidApiResponseException
            throw $e;
        } catch (Exception $e) {
            Log::error(self::SERVICE_NAME . ' auth unexpected error', [
                'exception' => $e::class,
                'error' => $e->getMessage(),
            ]);

            throw new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
        }
    }

    /**
     * Handle authentication request failures.
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
