<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\PurchaseOrderClientInterface;
use App\Application\Linnworks\DTOs\PurchaseOrder\AdditionalCostUpdateDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\DesiredExtendedPropertyDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\ExtendedPropertyUpdateDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\NewAdditionalCostDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\PurchaseOrderHeaderUpdateDTO;
use App\Application\Linnworks\DTOs\PurchaseOrder\PurchaseOrderLineItemDTO;
use App\Application\Linnworks\UseCases\PurchaseOrder\CreatePurchaseOrderCommand;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Linnworks\Enums\PurchaseOrderStatus;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderAdditionalCost;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderExtendedProperty;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderHeader;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderNote;
use App\Domain\Linnworks\ValueObjects\PurchaseOrderReference;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Responses\PurchaseOrder\PurchaseOrderAdditionalCostResponse;
use App\Infrastructure\Linnworks\Responses\PurchaseOrder\PurchaseOrderExtendedPropertyResponse;
use App\Infrastructure\Linnworks\Responses\PurchaseOrder\PurchaseOrderHeaderResponse;
use App\Infrastructure\Linnworks\Responses\PurchaseOrder\PurchaseOrderNoteResponse;
use App\Infrastructure\Linnworks\Support\LinnworksResponseParserTrait;
use DateTimeImmutable;
use JsonException;

/**
 * Linnworks PurchaseOrder API client.
 *
 * Wraps all 17 /api/PurchaseOrder/* endpoints with typed methods.
 * Three parameter encoding patterns preserved from legacy:
 * - Simple form params (postFormParams with scalar values)
 * - JSON in 'request' key (post with data array)
 * - JSON in custom key (postFormParams with JSON-encoded string value)
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class PurchaseOrderClient implements PurchaseOrderClientInterface
{
    use LinnworksResponseParserTrait;

    public function __construct(
        private LinnworksTransportInterface $transport,
    ) {}

    // ── Read Operations ──

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

    // ── Write Operations ──

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
    public function createPurchaseOrderInitial(CreatePurchaseOrderCommand $command, PurchaseOrderReference $reference): Guid
    {
        $response = $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/Create_PurchaseOrder_Initial',
            params: ['createParameters' => \json_encode([
                'fkSupplierId' => $command->fkSupplierId->value,
                'fkLocationId' => $command->fkLocationId->value,
                'ExternalInvoiceNumber' => $reference->value,
                'Currency' => $command->currency,
                'SupplierReferenceNumber' => $command->supplierReferenceNumber,
                'UnitAmountTaxIncludedType' => $command->unitAmountTaxIncludedType,
                'DateOfPurchase' => ($command->dateOfPurchase ?? new DateTimeImmutable())->format('Y-m-d\TH:i:s'),
                'QuotedDeliveryDate' => $command->quotedDeliveryDate?->format('Y-m-d\TH:i:s'),
                'PostagePaid' => $command->postagePaid->toNet(),
                'ShippingTaxRate' => $command->shippingTaxRate->percentage,
                'ConversionRate' => $command->conversionRate,
            ], JSON_THROW_ON_ERROR)],
        );

        $purchaseId = $response->json();

        if (!\is_string($purchaseId) || $purchaseId === '') {
            throw new InvalidApiResponseException(
                self::SERVICE_NAME,
                'Create_PurchaseOrder_Initial returned invalid response',
            );
        }

        // Response comes wrapped in quotes — strip them
        return Guid::fromTrusted(\mb_trim($purchaseId, '"'));
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
    public function addPurchaseOrderItem(Guid $purchaseId, PurchaseOrderLineItemDTO $item): void
    {
        $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/Add_PurchaseOrderItem',
            params: ['addItemParameter' => \json_encode($item->forApi($purchaseId->value), JSON_THROW_ON_ERROR)],
        );
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
    public function changePurchaseOrderStatus(Guid $purchaseId, PurchaseOrderStatus $status): void
    {
        $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/Change_PurchaseOrderStatus',
            params: ['changeStatusParameter' => \json_encode([
                'pkPurchaseId' => $purchaseId->value,
                'status' => $status->value,
            ], JSON_THROW_ON_ERROR)],
        );
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
    public function updatePurchaseOrderHeader(PurchaseOrderHeaderUpdateDTO $params): void
    {
        $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/Update_PurchaseOrderHeader',
            params: ['updateParameter' => \json_encode($params->forApi(), JSON_THROW_ON_ERROR)],
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
    public function addPurchaseOrderExtendedProperties(Guid $purchaseId, array $properties): void
    {
        $this->transport->post(
            endpoint: '/api/PurchaseOrder/Add_PurchaseOrderExtendedProperty',
            data: [
                'PurchaseId' => $purchaseId->value,
                'ExtendedPropertyItems' => \array_map(
                    static fn(DesiredExtendedPropertyDTO $ep): array => $ep->forApi(),
                    $properties,
                ),
            ],
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
    public function updatePurchaseOrderExtendedProperties(Guid $purchaseId, array $properties): void
    {
        $this->transport->post(
            endpoint: '/api/PurchaseOrder/Update_PurchaseOrderExtendedProperty',
            data: [
                'PurchaseId' => $purchaseId->value,
                'ExtendedPropertyItems' => \array_map(
                    static fn(ExtendedPropertyUpdateDTO $ep): array => $ep->forApi(),
                    $properties,
                ),
            ],
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
    public function deletePurchaseOrderExtendedProperties(Guid $purchaseId, array $rowIds): void
    {
        $this->transport->post(
            endpoint: '/api/PurchaseOrder/Delete_PurchaseOrderExtendedProperty',
            data: [
                'PurchaseId' => $purchaseId->value,
                'RowIds' => $rowIds,
            ],
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
    public function modifyAdditionalCosts(
        Guid $purchaseId,
        array $itemsToAdd = [],
        array $itemsToUpdate = [],
        array $itemIdsToDelete = [],
    ): void {
        $this->transport->post(
            endpoint: '/api/PurchaseOrder/Modify_AdditionalCost',
            data: [
                'PurchaseId' => $purchaseId->value,
                'itemsToAdd' => \array_map(
                    static fn(NewAdditionalCostDTO $cost): array => $cost->forApi(),
                    $itemsToAdd,
                ),
                'itemsToUpdate' => \array_map(
                    static fn(AdditionalCostUpdateDTO $cost): array => $cost->forApi(),
                    $itemsToUpdate,
                ),
                'itemsToDelete' => \array_map(
                    static fn(int $id): array => ['PurchaseAdditionalCostItemId' => $id],
                    $itemIdsToDelete,
                ),
            ],
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
    public function addPurchaseOrderNote(Guid $purchaseId, string $note): void
    {
        $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/Add_PurchaseOrderNote',
            params: [
                'pkPurchaseId' => $purchaseId->value,
                'Note' => $note,
            ],
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
    public function deletePurchaseOrder(Guid $purchaseId): void
    {
        $this->transport->postFormParams(
            endpoint: '/api/PurchaseOrder/Delete_PurchaseOrder',
            params: ['pkPurchaseId' => $purchaseId->value],
        );
    }
}
