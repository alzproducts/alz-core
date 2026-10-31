<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Responses;

use App\Application\Catalog\Results\ProductListFragmentDTO;
use Illuminate\Http\JsonResponse;

/**
 * The GET /api/products response, plus the fragments rendered for it that are not yet stored.
 */
final readonly class ProductListResponseDTO
{
    /**
     * @param list<ProductListFragmentDTO> $renderedFragments
     */
    public function __construct(
        public JsonResponse $response,
        public array $renderedFragments,
    ) {}
}
