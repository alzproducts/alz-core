<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Contracts;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use Illuminate\Http\Client\Response;

/**
 * HTTP transport contract for Linnworks API.
 *
 * Defines the HTTP operations used by Linnworks API clients.
 * Implementations handle authentication, retries, and error translation.
 */
interface LinnworksTransportInterface
{
    /**
     * Perform GET request to Linnworks API.
     *
     * @param string $endpoint API endpoint path (e.g., '/api/Inventory/GetInventoryItemById')
     * @param array<string, mixed> $query Query parameters
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function get(string $endpoint, array $query = []): Response;

    /**
     * Perform POST request to Linnworks API.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $data Request body data (JSON-encoded and sent as form 'request' parameter)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400) or data not serializable
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function post(string $endpoint, array $data = []): Response;

    /**
     * Perform POST request with raw JSON body.
     *
     * Unlike post(), this sends JSON directly in the request body (not wrapped
     * in a 'request' form parameter). Used by Linnworks endpoints like
     * UpdateInventoryItemField that expect application/json content type.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, mixed> $data Request body data (sent as JSON)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function postJson(string $endpoint, array $data = []): Response;

    /**
     * Perform POST request with raw form-encoded parameters.
     *
     * Unlike post(), this sends parameters directly as form fields (not wrapped
     * in a 'request' JSON blob). Used by Linnworks endpoints like GetStockItemsFull
     * that expect query-string style parameters in the POST body.
     *
     * Array/object values are automatically JSON-encoded as string values.
     *
     * @param string $endpoint API endpoint path
     * @param array<string, scalar|array<mixed>|null> $params Form parameters (arrays will be JSON-encoded)
     *
     * @return Response Successful HTTP response
     *
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws InvalidApiResponseException When session data is malformed (API contract violation)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotFoundException When resource not found (404)
     * @throws ExternalServiceUnavailableException When API unavailable, rate limited, or connection fails
     */
    public function postFormParams(string $endpoint, array $params = []): Response;
}
