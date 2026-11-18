<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Infrastructure\Support\TransientLogThrottle;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Posts to Intuit's OAuth token endpoint. Request and response bodies carry tokens, so neither is ever logged.
 */
final readonly class QuickBooksOAuthHttpTransport
{
    public const string SERVICE_NAME = 'QuickBooks';

    private const string LOG_PREFIX = 'QuickBooks OAuth';

    private const int TIMEOUT_SECONDS = 15;

    public function __construct(
        private QuickBooksConfig $config,
        private TransientLogThrottle $logThrottle,
        private LoggerInterface $logger,
    ) {}

    /**
     * @param array<string, string> $form
     *
     * @return array<mixed> the decoded token response body
     *
     * @throws AuthenticationExpiredException     on invalid_grant or a 401
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException        when a 2xx body is not a JSON object
     */
    public function requestToken(#[SensitiveParameter] array $form): array
    {
        try {
            $response = Http::timeout(self::TIMEOUT_SECONDS)
                ->asForm()
                ->acceptJson()
                ->withBasicAuth($this->config->clientId, $this->config->clientSecret)
                ->post(QuickBooksConfig::TOKEN_URL, $form);
        } catch (ConnectionException $e) {
            throw $this->transientFailure($e, 'connection failed');
        } catch (Exception $e) {
            throw $this->transientFailure($e, 'unexpected error');
        }

        if (!$response->successful()) {
            throw $this->statusFailure($response);
        }

        $body = $response->json();
        if (!\is_array($body)) {
            $this->logger->error(self::LOG_PREFIX . ' token response is not a JSON object', [
                'status' => $response->status(),
            ]);

            throw new InvalidApiResponseException(self::SERVICE_NAME, 'Token response is not a JSON object');
        }

        return $body;
    }

    private function statusFailure(Response $response): AuthenticationExpiredException|ExternalServiceUnavailableException
    {
        $status = $response->status();
        $errorCode = $response->json('error');
        $context = ['status' => $status, 'error' => \is_string($errorCode) ? $errorCode : null];

        if ($status === 401 || ($status === 400 && $errorCode === 'invalid_grant')) {
            $this->logger->error(self::LOG_PREFIX . ' grant rejected', $context);

            return new AuthenticationExpiredException(self::SERVICE_NAME, 'QuickBooks rejected the grant; reconnect with quickbooks:connect');
        }

        if ($status === 429 || $status >= 500) {
            $this->logThrottle->logTransient(self::SERVICE_NAME, self::LOG_PREFIX . ' request failed', $context);
        } else {
            $this->logger->error(self::LOG_PREFIX . ' request returned an unexpected status', $context);
        }

        return new ExternalServiceUnavailableException(self::SERVICE_NAME);
    }

    private function transientFailure(Exception $e, string $event): ExternalServiceUnavailableException
    {
        $this->logThrottle->logTransient(self::SERVICE_NAME, self::LOG_PREFIX . ' ' . $event, [
            'exception' => $e::class,
        ]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}
