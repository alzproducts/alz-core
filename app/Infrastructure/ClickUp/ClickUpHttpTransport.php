<?php

declare(strict_types=1);

namespace App\Infrastructure\ClickUp;

use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use Exception;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;

final readonly class ClickUpHttpTransport
{
    public function __construct(
        private ClickUpConfig $config,
        private HttpFactory $httpFactory,
    ) {}

    /**
     * @param array<string, mixed> $query
     *
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function get(ApiKeyToken $token, string $endpoint, array $query = []): Response
    {
        try {
            return $this->createRequest($token)
                ->get($this->config->baseUrl . $endpoint, $query)
                ->throw();
        } catch (RequestException $e) {
            throw ClickUpErrorHandler::handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw ClickUpErrorHandler::handleConnectionException($e, $endpoint);
        } catch (Exception $e) {
            throw ClickUpErrorHandler::handleUnexpectedException($e, $endpoint);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function post(ApiKeyToken $token, string $endpoint, array $body = []): Response
    {
        try {
            return $this->createRequest($token)
                ->post($this->config->baseUrl . $endpoint, $body)
                ->throw();
        } catch (RequestException $e) {
            throw ClickUpErrorHandler::handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw ClickUpErrorHandler::handleConnectionException($e, $endpoint);
        } catch (Exception $e) {
            throw ClickUpErrorHandler::handleUnexpectedException($e, $endpoint);
        }
    }

    /**
     * @param array<string, mixed> $body
     *
     * @throws InvalidApiRequestException
     * @throws AuthenticationExpiredException
     * @throws ResourceNotFoundException
     * @throws ExternalServiceUnavailableException
     */
    public function put(ApiKeyToken $token, string $endpoint, array $body = []): Response
    {
        try {
            return $this->createRequest($token)
                ->put($this->config->baseUrl . $endpoint, $body)
                ->throw();
        } catch (RequestException $e) {
            throw ClickUpErrorHandler::handleRequestException($e, $endpoint);
        } catch (ConnectionException $e) {
            throw ClickUpErrorHandler::handleConnectionException($e, $endpoint);
        } catch (Exception $e) {
            throw ClickUpErrorHandler::handleUnexpectedException($e, $endpoint);
        }
    }

    private function createRequest(ApiKeyToken $token): PendingRequest
    {
        /** @phpstan-ignore staticMethod.dynamicCall (Factory uses __call to proxy to PendingRequest) */
        return $this->httpFactory
            ->withToken($token->value)
            ->timeout($this->config->timeoutSeconds)
            ->acceptJson();
    }
}
