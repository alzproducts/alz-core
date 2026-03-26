<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Controllers;

use App\Application\Catalog\UseCases\ListFilterGroupsUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Api\DTOs\ListFilterGroupsRequestDTO;
use App\Presentation\Http\Api\Resources\FilterGroupResource;
use App\Presentation\Http\Api\Traits\BuildsPaginatedResponseTrait;
use Illuminate\Http\Resources\Json\ResourceCollection;

/**
 * Consumer API controller for filter groups.
 *
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 */
final readonly class FilterGroupController
{
    use BuildsPaginatedResponseTrait;

    public function __construct(
        private ListFilterGroupsUseCase $listFilterGroupsUseCase,
    ) {}

    /**
     * List all filter groups.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function index(ListFilterGroupsRequestDTO $data): ResourceCollection
    {
        $result = $this->listFilterGroupsUseCase->execute(
            perPage: $data->per_page,
            page: $data->page,
        );

        return $this->paginatedResponse($result, FilterGroupResource::class);
    }
}
