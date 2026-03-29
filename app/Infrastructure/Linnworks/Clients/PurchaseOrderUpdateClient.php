<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Clients;

use App\Application\Contracts\Linnworks\PurchaseOrderUpdateClientInterface;
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
use App\Domain\Linnworks\ValueObjects\PurchaseOrderReference;
use App\Domain\ValueObjects\Guid;
use App\Infrastructure\Linnworks\Contracts\LinnworksTransportInterface;
use App\Infrastructure\Linnworks\Support\LinnworksResponseParserTrait;
use DateTimeImmutable;
use JsonException;

/**
 * Linnworks PurchaseOrder write operations client.
 *
 * Handles all write operations (create, update, delete) for purchase orders.
 * Read operations are handled by PurchaseOrderClient.
 *
 * @template-pattern Infrastructure API Client
 */
final readonly class PurchaseOrderUpdateClient implements PurchaseOrderUpdateClientInterface
{
    use LinnworksResponseParserTrait;

    public function __construct(
        private LinnworksTransportInterface $transport,
    ) {}

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
