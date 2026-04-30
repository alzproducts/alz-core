<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Responses;

use App\Application\Inventory\Results\GenerateVariantSkusResult;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * JSON response envelope for variant SKU generation.
 *
 * Returns 200 when all variants succeeded (or all were skipped),
 * 207 Multi-Status when there are partial failures.
 *
 * Implements Responsable so controllers return this directly.
 */
final readonly class GenerateVariantSkusResponseDTO implements Responsable
{
    /**
     * @param list<string> $createdVariants
     * @param list<int> $failedVariationIds
     */
    public function __construct(
        private string $productTitle,
        private int $total,
        private int $skipped,
        private int $created,
        private int $failed,
        private array $createdVariants,
        private array $failedVariationIds,
    ) {}

    public static function fromResult(GenerateVariantSkusResult $result): self
    {
        return new self(
            productTitle: $result->productTitle,
            total: $result->total,
            skipped: $result->skipped,
            created: $result->created,
            failed: $result->failed,
            createdVariants: $result->createdVariants,
            failedVariationIds: $result->failedVariationIds,
        );
    }

    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse([
            'product_title' => $this->productTitle,
            'total' => $this->total,
            'skipped' => $this->skipped,
            'created' => $this->created,
            'failed' => $this->failed,
            'created_variants' => $this->createdVariants,
            'failed_variation_ids' => $this->failedVariationIds,
        ], $this->statusCode());
    }

    private function statusCode(): int
    {
        if ($this->created > 0 && $this->failed > 0) {
            return Response::HTTP_MULTI_STATUS;
        }

        return Response::HTTP_OK;
    }
}
