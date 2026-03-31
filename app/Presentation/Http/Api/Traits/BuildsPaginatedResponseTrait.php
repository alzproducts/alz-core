<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Traits;

use App\Domain\Shared\Pagination\ValueObjects\PagedList;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\Json\ResourceCollection;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Convert PagedList to a Laravel ResourceCollection.
 *
 * Reconstructs a LengthAwarePaginator from the framework-free value object,
 * preserving query string parameters in pagination links.
 */
trait BuildsPaginatedResponseTrait
{
    /**
     * @param PagedList<mixed> $result
     * @param class-string<JsonResource> $resourceClass
     */
    protected function paginatedResponse(PagedList $result, string $resourceClass): ResourceCollection
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
