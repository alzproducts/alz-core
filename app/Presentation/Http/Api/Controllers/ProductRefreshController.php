<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\RefreshAllProductsUseCase;
use App\Application\Catalog\UseCases\RefreshProductViewUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\PartialPersistenceFailureException;
use App\Domain\ValueObjects\IntId;
use App\Presentation\Http\Api\Responses\AsyncRefreshAcceptedResponseDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consumer API endpoints for product refresh operations.
 *
 * All endpoints require Supabase JWT authentication + approval gate.
 */
final readonly class ProductRefreshController
{
    public function __construct(
        private RefreshProductViewUseCase $refreshUseCase,
        private RefreshAllProductsUseCase $refreshAllUseCase,
    ) {}

    /**
     * Force-refresh a product's data from ShopWired and Linnworks synchronously.
     *
     * @throws ResourceNotAvailableException When product not found in ShopWired (404)
     * @throws ResourceNotFoundException When Linnworks resource not found
     * @throws AuthenticationExpiredException When credentials invalid (401/403)
     * @throws InvalidApiRequestException When request parameters invalid (400)
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException When database save fails
     * @throws DuplicateRecordException When unique constraint violated
     * @throws MissingRequiredDataException When product has no SKUs or no matching stock items
     * @throws PartialPersistenceFailureException When some stock items fail to persist
     */
    public function refresh(int $productId): JsonResponse
    {
        $this->refreshUseCase->execute(IntId::from($productId));

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }

    /**
     * Force-refresh the full product catalogue + Linnworks stock items asynchronously.
     *
     * Dispatches SyncShopwiredProductsJob + SyncLinnworksStockItemsJob. Both jobs carry
     * ShouldBeUnique guards, so concurrent dispatches (scheduled or on-demand) are deduped
     * silently — a 202 means "dispatch attempted", not "a new job is queued".
     */
    public function refreshAll(): AsyncRefreshAcceptedResponseDTO
    {
        $this->refreshAllUseCase->execute();

        return new AsyncRefreshAcceptedResponseDTO(
            message: 'Product & stock refresh queued',
            estimatedDurationSeconds: RefreshAllProductsUseCase::ESTIMATED_DURATION_SECONDS,
        );
    }
}
