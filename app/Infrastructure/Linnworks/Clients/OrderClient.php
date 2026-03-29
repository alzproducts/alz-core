<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\OrderClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\ValueObjects\LinnworksOrder;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Responses\GetOrdersApiResponse;
use App\Infrastructure\Linnworks\Responses\OrderResponse;
use App\Infrastructure\Linnworks\Support\LinnworksResponseParserTrait;
use DateTimeImmutable;
use Generator;
use Spatie\LaravelData\Exceptions\CannotCreateData;

/**
 * Linnworks orders API client.
 *
 * Uses the v2 GetOrders endpoint with token-based pagination.
 * Pagination is handled internally — external callers see a Generator of batches.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class OrderClient implements OrderClientInterface
{
    use LinnworksResponseParserTrait;

    private const int ENTRIES_PER_PAGE = 200;

    private const int IDS_PER_CHUNK = 80;

    public function __construct(
        private LinnworksTransportInterface $transport,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @return Generator<int, list<LinnworksOrder>, mixed, void>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    public function iterateProcessedOrders(DateTimeImmutable $fromDate): Generator
    {
        $searchToken = null;
        $page = 0;

        do {
            $apiResponse = $this->fetchPage($fromDate, $searchToken);

            $orders = self::mapOrderResponses($apiResponse->processedOrders ?? []);

            if ($orders !== []) {
                yield $page => $orders;
            }

            $searchToken = $apiResponse->nextSearchToken;
            $page++;
        } while ($searchToken !== null);
    }

    /**
     * {@inheritDoc}
     *
     * @return Generator<int, list<LinnworksOrder>, mixed, void>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    public function iterateProcessedOrdersByIds(array $orderIds): Generator
    {
        if ($orderIds === []) {
            return;
        }

        $chunks = \array_chunk($orderIds, self::IDS_PER_CHUNK);

        foreach ($chunks as $chunkIndex => $chunk) {
            $apiResponse = $this->fetchPageWithIds($chunk);

            $orders = self::mapOrderResponses($apiResponse->processedOrders ?? []);

            if ($orders !== []) {
                yield $chunkIndex => $orders;
            }
        }
    }

    /**
     * {@inheritDoc}
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When order not found
     */
    public function getOrderById(Guid $orderId): LinnworksOrder
    {
        $apiResponse = $this->fetchPageWithId($orderId);

        $processedOrders = $apiResponse->processedOrders ?? [];

        if ($processedOrders === []) {
            throw new ResourceNotFoundException(self::SERVICE_NAME, 'Order', $orderId->value);
        }

        return $processedOrders[0]->toDomain();
    }

    /**
     * Fetch a single page of processed orders from the v2 GetOrders endpoint.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    private function fetchPage(DateTimeImmutable $fromDate, ?string $searchToken): GetOrdersApiResponse
    {
        $query = [
            'fromDate' => $fromDate->format('c'),
            'entriesPerPage' => self::ENTRIES_PER_PAGE,
            'includeProcessed' => 'true',
        ];

        if ($searchToken !== null) {
            $query['searchToken'] = $searchToken;
        }

        $response = $this->transport->get('/v2/orders', $query);

        return $this->parseGetOrdersResponse($response->json());
    }

    /**
     * Fetch a specific order by ID using the v2 GetOrders endpoint.
     *
     * The `id` parameter overrides all other filters in the v2 endpoint.
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    private function fetchPageWithId(Guid $orderId): GetOrdersApiResponse
    {
        $response = $this->transport->get('/v2/orders', [
            'id' => [$orderId->value],
            'includeProcessed' => 'true',
        ]);

        return $this->parseGetOrdersResponse($response->json());
    }

    /**
     * Map OrderResponse DTOs to domain objects.
     *
     * @param list<OrderResponse> $responses
     *
     * @return list<LinnworksOrder>
     *
     * @throws InvalidApiResponseException When date parsing fails in toDomain()
     */
    private static function mapOrderResponses(array $responses): array
    {
        return \array_map(
            static fn(OrderResponse $dto): LinnworksOrder => $dto->toDomain(),
            $responses,
        );
    }

    /**
     * Fetch multiple orders by IDs using the v2 GetOrders endpoint.
     *
     * The `id` parameter overrides all other filters in the v2 endpoint.
     *
     * @param list<Guid> $ids
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found (404)
     */
    private function fetchPageWithIds(array $ids): GetOrdersApiResponse
    {
        $response = $this->transport->get('/v2/orders', [
            'id' => \array_map(static fn(Guid $g): string => $g->value, $ids),
            'entriesPerPage' => self::IDS_PER_CHUNK,
            'includeProcessed' => 'true',
        ]);

        return $this->parseGetOrdersResponse($response->json());
    }

    /**
     * Parse the raw API response into a GetOrdersApiResponse DTO.
     *
     * @throws InvalidApiResponseException When response structure is invalid
     */
    private function parseGetOrdersResponse(mixed $data): GetOrdersApiResponse
    {
        if ($data === null || !\is_array($data)) {
            self::logParsingFailure('Expected object response for GetOrders', $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'Expected object response for GetOrders',
            );
        }

        try {
            return GetOrdersApiResponse::from($data);
        } catch (CannotCreateData $e) {
            self::logParsingFailure($e->getMessage(), $data);

            throw new InvalidApiResponseException(
                serviceName: self::SERVICE_NAME,
                message: 'API returned invalid order data structure',
                previous: $e,
            );
        }
    }
}
