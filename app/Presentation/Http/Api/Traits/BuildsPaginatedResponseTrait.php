<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Traits;

use App\Domain\ValueObjects\PaginatedList;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Convert PaginatedList to a Laravel ResourceCollection.
 *
 * Reconstructs a LengthAwarePaginator from the framework-free DTO,
 * preserving query string parameters in pagination links.
 */
trait BuildsPaginatedResponseTrait
{
    /**
     * @param PaginatedList<mixed> $result
     * @param class-string<JsonResource> $resourceClass
     */
    protected function paginatedResponse(PaginatedList $result, string $resourceClass): ResourceCollection
    {
        $paginator = (new LengthAwarePaginator(
            items: $result->items,
            total: $result->total,
            perPage: $result->perPage,
            currentPage: $result->currentPage,
        ))->withQueryString();

        return $resourceClass::collection($paginator);
    }
}
