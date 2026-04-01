<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Universal JSON response envelope for bulk update API endpoints.
 *
 * Guarantees a consistent top-level shape:
 *
 *     {"total": 5, "succeeded": 3, "failures": [{"sku": "...", "error": "..."}]}
 *
 * Implements Responsable so controllers can return this directly.
 */
final readonly class BulkUpdateResponseDTO implements Responsable
{
    /**
     * @param int<0, max> $total Total items submitted
     * @param int<0, max> $succeeded Items that succeeded
     * @param list<array{sku: string, error: string}> $failures Per-item failures
     */
    public function __construct(
        private int $total,
        private int $succeeded,
        private array $failures,
    ) {}

    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse([
            'total' => $this->total,
            'succeeded' => $this->succeeded,
            'failures' => $this->failures,
        ], Response::HTTP_OK);
    }
}
