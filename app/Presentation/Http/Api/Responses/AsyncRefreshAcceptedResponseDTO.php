<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 202 Accepted envelope for async refresh endpoints.
 *
 * Renders as:
 *
 *     {"message": "...", "estimated_duration_seconds": 120}
 */
final readonly class AsyncRefreshAcceptedResponseDTO implements Responsable
{
    public function __construct(
        private string $message,
        private int $estimatedDurationSeconds,
    ) {}

    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse([
            'message' => $this->message,
            'estimated_duration_seconds' => $this->estimatedDurationSeconds,
        ], Response::HTTP_ACCEPTED);
    }
}
