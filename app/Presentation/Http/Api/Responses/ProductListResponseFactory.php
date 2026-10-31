<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Responses;

use App\Application\Catalog\Results\ProductListEntryDTO;
use App\Application\Catalog\Results\ProductListFragmentDTO;
use App\Application\Catalog\Results\ProductListPageResult;
use App\Presentation\Http\Api\Resources\ProductResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\PaginatedResourceResponse;
use Illuminate\Pagination\LengthAwarePaginator;
use LogicException;

/**
 * Builds the GET /api/products body from raw JSON fragments: stored bodies are spliced in as-is and
 * misses are rendered by {@see ProductResource}, so the bytes match `ProductResource::collection()`.
 */
final class ProductListResponseFactory
{
    /** The flags ResourceResponse encodes with: ResourceCollection::jsonOptions() is 0. */
    private const int JSON_FLAGS = 0;

    public static function make(ProductListPageResult $page, Request $request): ProductListResponseDTO
    {
        $bodies = [];
        $rendered = [];

        foreach ($page->entries as $entry) {
            $body = $entry->cachedBody ?? self::render($entry, $request);
            $bodies[] = $body;

            if ($entry->cachedBody === null) {
                $rendered[] = new ProductListFragmentDTO($entry->productId, $page->shape, $entry->fingerprint, $body);
            }
        }

        $json = '{"data":[' . \implode(',', $bodies) . '],' . \mb_substr(self::envelope($page, $request), 1);

        return new ProductListResponseDTO(new JsonResponse($json, JsonResponse::HTTP_OK, [], self::JSON_FLAGS, true), $rendered);
    }

    public static function render(ProductListEntryDTO $entry, Request $request): string
    {
        if ($entry->view === null) {
            throw new LogicException('A product list miss carries its view');
        }

        return self::encode((new ProductResource($entry->view))->resolve($request));
    }

    /**
     * Laravel's own `links` and `meta`, over a paginator of the page's ids.
     */
    private static function envelope(ProductListPageResult $page, Request $request): string
    {
        $response = new class (ProductResource::collection(self::paginator($page))) extends PaginatedResourceResponse {
            /** @return array<mixed> */
            public function envelope(Request $request): array
            {
                return $this->paginationInformation($request);
            }
        };

        $envelope = self::encode($response->envelope($request));

        if (! \str_starts_with($envelope, '{')) {
            throw new LogicException('The pagination envelope must be a JSON object');
        }

        return $envelope;
    }

    /**
     * Built exactly as BuildsPaginatedResponseTrait builds it, so `links` and `meta` match.
     *
     * @return LengthAwarePaginator<int, string>
     */
    private static function paginator(ProductListPageResult $page): LengthAwarePaginator
    {
        return (new LengthAwarePaginator(
            items: \array_map(static fn(ProductListEntryDTO $entry): string => $entry->productId, $page->entries),
            total: $page->total,
            perPage: $page->perPage,
            currentPage: $page->currentPage,
        ))->withQueryString();
    }

    /**
     * @param array<mixed> $value
     */
    private static function encode(array $value): string
    {
        $json = \json_encode($value, self::JSON_FLAGS);

        if ($json === false) {
            throw new LogicException('Product list JSON encoding failed');
        }

        return $json;
    }
}
