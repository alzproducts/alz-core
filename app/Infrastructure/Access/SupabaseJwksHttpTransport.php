<?php

declare(strict_types=1);

namespace App\Infrastructure\Access;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Infrastructure\Support\TransientLogThrottle;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Fetches the Supabase Auth JWKS document.
 */
final readonly class SupabaseJwksHttpTransport
{
    public const string SERVICE_NAME = 'supabase-jwks';

    private const string LOG_PREFIX = 'Supabase JWKS';

    private const int CONNECT_TIMEOUT_SECONDS = 2;

    private const int TIMEOUT_SECONDS = 5;

    public function __construct(
        private SupabaseAuthConfig $config,
        private TransientLogThrottle $logThrottle,
    ) {}

    /**
     * @return list<mixed> the JWKS `keys` member, unfiltered
     *
     * @throws ExternalServiceUnavailableException When the request fails, the status is not 200,
     *                                             or the body is not a JSON object with a `keys` array
     */
    public function fetchKeys(): array
    {
        try {
            $response = Http::connectTimeout(self::CONNECT_TIMEOUT_SECONDS)
                ->timeout(self::TIMEOUT_SECONDS)
                ->acceptJson()
                ->get($this->config->jwksUrl);
        } catch (ConnectionException $e) {
            throw $this->transientFailure($e, 'connection failed');
        } catch (Exception $e) {
            throw $this->transientFailure($e, 'unexpected error');
        }

        return $this->parseKeys($response);
    }

    /**
     * @return list<mixed>
     *
     * @throws ExternalServiceUnavailableException
     */
    private function parseKeys(Response $response): array
    {
        $status = $response->status();
        if ($status !== 200) {
            throw $this->statusFailure($status);
        }

        $body = $response->json();
        if (!\is_array($body) || !isset($body['keys']) || !\is_array($body['keys'])) {
            Log::error(self::LOG_PREFIX . ' response is not a JSON object with a keys array', [
                'url' => $this->config->jwksUrl,
            ]);

            throw new ExternalServiceUnavailableException(self::SERVICE_NAME);
        }

        return \array_values($body['keys']);
    }

    private function statusFailure(int $status): ExternalServiceUnavailableException
    {
        $context = ['url' => $this->config->jwksUrl, 'status' => $status];

        if ($status >= 500) {
            $this->logThrottle->logTransient(self::SERVICE_NAME, self::LOG_PREFIX . ' request failed', $context);
        } else {
            Log::error(self::LOG_PREFIX . ' request returned an unexpected status', $context);
        }

        return new ExternalServiceUnavailableException(self::SERVICE_NAME);
    }

    private function transientFailure(Exception $e, string $event): ExternalServiceUnavailableException
    {
        $this->logThrottle->logTransient(self::SERVICE_NAME, self::LOG_PREFIX . ' ' . $event, [
            'url' => $this->config->jwksUrl,
            'exception' => $e::class,
            'error' => $e->getMessage(),
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}
