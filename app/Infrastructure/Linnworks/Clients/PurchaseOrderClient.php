<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderAdditionalCost;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderExtendedProperty;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderHeader;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderNote;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Responses\PurchaseOrder\PurchaseOrderAdditionalCostResponse;
use App\Infrastructure\Linnworks\Responses\PurchaseOrder\PurchaseOrderExtendedPropertyResponse;
use App\Infrastructure\Linnworks\Responses\PurchaseOrder\PurchaseOrderHeaderResponse;
use App\Infrastructure\Linnworks\Responses\PurchaseOrder\PurchaseOrderNoteResponse;
use App\Infrastructure\Linnworks\Support\LinnworksResponseParserTrait;
use JsonException;

/**
 * Linnworks PurchaseOrder read operations client.
 *
 * Write operations are handled by PurchaseOrderUpdateClient.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class PurchaseOrderClient implements PurchaseOrderClientInterface
{
    use LinnworksResponseParserTrait;

    public function __construct(
        private LinnworksTransportInterface $transport,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws ResourceNotFoundException When PO doesn't exist
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     */
    public function getPurchaseOrder(Guid $purchaseId): PurchaseOrderHeader
    {
        $response = $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/Get_PurchaseOrder',
            params: ['pkPurchaseId' => $purchaseId->value],
        );

        $data = $response->json();

        if ($data === null) {
            throw new ResourceNotFoundException(self::SERVICE_NAME, 'PurchaseOrder', $purchaseId->value);
        }

        /** @var PurchaseOrderHeader */
        return self::parseSingleToDomain($data, PurchaseOrderHeaderResponse::class);
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException When JSON encoding fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function searchPurchaseOrders(array $searchParams): array
    {
        $response = $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/Search_PurchaseOrders',
            params: ['searchParameter' => \json_encode($searchParams, JSON_THROW_ON_ERROR)],
        );

        $data = self::validateArrayResponse(
            $response->json(),
            'Search_PurchaseOrders returned non-array response',
        );

        /** @var list<array<string, mixed>> $results */
        $results = $data['Result'] ?? [];
        /** @var int $totalRecords */
        $totalRecords = $data['TotalNumberOfRecords'] ?? 0;

        return [
            'results' => $results,
            'totalRecords' => $totalRecords,
        ];
    }

    /**
     * {@inheritDoc}
     *
     * @return list<PurchaseOrderExtendedProperty>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function getPurchaseOrderExtendedProperties(Guid $purchaseId): array
    {
        $response = $this->transport->post(
            endpoint: '/api/PurchaseOrder/Get_PurchaseOrderExtendedProperty',
            data: ['PurchaseId' => $purchaseId->value],
        );

        /** @var list<PurchaseOrderExtendedProperty> */
        return self::parseWrappedArrayToDomain(
            $response->json(),
            PurchaseOrderExtendedPropertyResponse::class,
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return list<PurchaseOrderAdditionalCost>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function getAdditionalCosts(Guid $purchaseId): array
    {
        $response = $this->transport->post(
            endpoint: '/api/PurchaseOrder/Get_Additional_Cost',
            data: ['PurchaseId' => $purchaseId->value],
        );

        // Note: This endpoint returns lowercase 'items' (not 'Items')
        /** @var list<PurchaseOrderAdditionalCost> */
        return self::parseWrappedArrayToDomain(
            $response->json(),
            PurchaseOrderAdditionalCostResponse::class,
            'items',
        );
    }

    /**
     * {@inheritDoc}
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function getAdditionalCostTypes(): array
    {
        $response = $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/Get_AdditionalCostTypes',
        );

        /** @var array<string, mixed> */
        return self::validateArrayResponse(
            $response->json(),
            'Get_AdditionalCostTypes returned non-array response',
        );
    }

    /**
     * {@inheritDoc}
     *
     * @return list<PurchaseOrderNote>
     *
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function getPurchaseOrderNotes(Guid $purchaseId): array
    {
        $response = $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/Get_PurchaseOrderNote',
            params: ['pkPurchaseId' => $purchaseId->value],
        );

        $data = $response->json();

        if ($data === null) {
            return [];
        }

        /** @var list<PurchaseOrderNote> */
        return self::parseDirectArrayToDomain($data, PurchaseOrderNoteResponse::class);
    }

    /**
     * {@inheritDoc}
     *
     * @throws JsonException When JSON encoding fails
     * @throws AuthenticationExpiredException When credentials are invalid
     * @throws ExternalServiceUnavailableException When API is unavailable
     * @throws InvalidApiRequestException When request parameters are invalid
     * @throws InvalidApiResponseException When API response structure is invalid
     * @throws ResourceNotFoundException When resource not found
     */
    public function getPurchaseOrdersWithStockItems(string $stockItemId, array $locationIds): array
    {
        $response = $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/GetPurchaseOrdersWithStockItems',
            params: ['purchaseOrder' => \json_encode([
                'StockItemId' => $stockItemId,
                'LocationIds' => $locationIds,
            ], JSON_THROW_ON_ERROR)],
        );

        /** @var list<string> */
        return self::validateArrayResponse(
            $response->json(),
            'GetPurchaseOrdersWithStockItems returned non-array response',
        );
    }
}
