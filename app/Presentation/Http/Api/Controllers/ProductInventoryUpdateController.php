<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Inventory\UseCases\UpdateVariationInventoryUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\UpdateInventoryItemDTO;
use App\Presentation\Http\Api\DTOs\UpdateInventoryRequestDTO;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Consumer API endpoint for product inventory field updates (JIT, MinimumLevel).
 *
 * Requires Supabase JWT authentication + approval gate.
 */
final readonly class ProductInventoryUpdateController
{
    public function __construct(
        private UpdateVariationInventoryUseCase $useCase,
    ) {}

    /**
     * @throws InvalidSkuException When SKU format is invalid
     * @throws ResourceNotFoundException When stock item not found in Linnworks (404)
     * @throws InvalidApiRequestException When parameters invalid
     * @throws InvalidApiResponseException When API response malformed
     * @throws AuthenticationExpiredException When credentials invalid
     * @throws ExternalServiceUnavailableException When API unavailable
     * @throws DatabaseOperationFailedException On local DB write failure
     * @throws DuplicateRecordException On local DB constraint violation
     */
    public function update(UpdateInventoryRequestDTO $data): JsonResponse
    {
        /** @var UpdateInventoryItemDTO $item */
        $item = $data->items[0];

        $this->useCase->execute($item->toCommand());

        return new JsonResponse(null, Response::HTTP_NO_CONTENT);
    }
}
