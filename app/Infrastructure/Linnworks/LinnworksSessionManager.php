<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks;

use App\Application\Contracts\LockableCacheInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use DateMalformedStringException;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use JsonException;

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
        $data = $this->performAuthRequest();

        return LinnworksSession::fromAuthResponse($data, $this->config->cacheTtlBuffer);
    }

    /**
     * @return array<string, mixed>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When auth endpoint unavailable
     */
    private function performAuthRequest(): array
    {
        try {
            return $this->sendAuthHttpRequest();
        } catch (RequestException $e) {
            $this->handleAuthRequestException($e);
        } catch (ConnectionException $e) {
            throw $this->logAndBuildUnavailable($e, 'auth connection failed', ['error' => $e->getMessage()]);
        } catch (JsonException $e) {
            throw $this->logAndBuildUnavailable($e, 'auth response JSON decode failed', [
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return array<string, mixed>
     *
     * @throws RequestException When the Linnworks API returns a non-2xx response
     * @throws ConnectionException When the HTTP connection fails
     * @throws JsonException When the auth response body is not valid JSON
     */
    private function sendAuthHttpRequest(): array
    {
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

        return $data;
    }

    /**
     * @param array<string, mixed> $context
     */
    private function logAndBuildUnavailable(Exception $e, string $event, array $context): ExternalServiceUnavailableException
    {
        Log::error(self::SERVICE_NAME . ' ' . $event, $context);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
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
        $isAuthFailure = ($status === 401) || ($status === 403);

        Log::error(
            self::SERVICE_NAME . ($isAuthFailure ? ' authentication failed' : ' auth endpoint error'),
            ['status' => $status, 'error' => $e->getMessage()],
        );

        throw $isAuthFailure
            ? new AuthenticationExpiredException(
                self::SERVICE_NAME,
                'Invalid credentials or application not authorized',
                $e,
            )
            : new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}
