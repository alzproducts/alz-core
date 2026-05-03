<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\ListVariationsUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\ListVariationsRequestDTO;
use App\Presentation\Http\Api\Resources\VariationListResource;
use App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Consumer API controller for standalone variation listing.
 *
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 */
final readonly class VariationController
{
    use BuildsPaginatedResponseTrait;

    public function __construct(
        private ListVariationsUseCase $listVariationsUseCase,
    ) {}

    /**
     * List variations as first-class catalog rows with denormalized parent context.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    public function index(ListVariationsRequestDTO $data): ResourceCollection
    {
        $result = $this->listVariationsUseCase->execute($data->toQuery());

        return $this->paginatedResponse($result, VariationListResource::class);
    }
}
