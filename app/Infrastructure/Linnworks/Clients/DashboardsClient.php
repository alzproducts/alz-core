<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Infrastructure\Linnworks\Contracts\LinnworksQueryInterface;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Responses\SqlQueryResponse;
use Illuminate\Support\Facades\Log;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Low-level Linnworks Dashboards API client.
 *
 * Executes query objects against the Dashboards/ExecuteCustomScriptQuery endpoint.
 * NOT for direct use - compose into category-specific facade clients like
 * StockDashboardsClient.
 *
 * @internal
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class DashboardsClient
{
    private const string ENDPOINT = 'Dashboards/ExecuteCustomScriptQuery';

    private const string SERVICE_NAME = 'Linnworks';

    public function __construct(
        private LinnworksTransportInterface $transport,
    ) {}

    /**
     * Execute a query object and return typed result.
     *
     * @template TResult
     *
     * @param LinnworksQueryInterface<TResult> $query
     *
     * @return TResult
     *
     * @throws InvalidApiResponseException When query fails or response malformed
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ResourceNotFoundException When resource not found
     * @throws ExternalServiceUnavailableException When API unavailable
     */
    public function execute(LinnworksQueryInterface $query): mixed
    {
        $sql = $query->buildSql();
        $response = $this->transport->post(self::ENDPOINT, ['Script' => $sql]);

        try {
            $dto = SqlQueryResponse::from($response->json());
        } catch (CannotCreateData $e) {
            Log::critical('Linnworks SQL query response validation failed', [
                'error' => $e->getMessage(),
                'response' => $response->json(),
            ]);

            throw new InvalidApiResponseException(
                self::SERVICE_NAME,
                'SQL query response malformed',
                $e,
            );
        }

        if ($dto->isError) {
            Log::error('Linnworks SQL query returned error', [
                'sql' => $sql,
                'response' => $response->json(),
            ]);

            throw new InvalidApiResponseException(
                self::SERVICE_NAME,
                'SQL query returned error',
            );
        }

        return $query->mapResponse($dto);
    }
}
