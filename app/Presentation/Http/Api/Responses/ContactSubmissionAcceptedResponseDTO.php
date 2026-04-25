<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Responses;

use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * 200 OK envelope for contact form submission endpoints.
 *
 * Returns the submission ID so the frontend can reference the submission.
 * Implements Responsable so controllers return this directly.
 */
final readonly class ContactSubmissionAcceptedResponseDTO implements Responsable
{
    public function __construct(private string $submissionId) {}

    public static function from(string $submissionId): self
    {
        return new self($submissionId);
    }

    public function toResponse(mixed $request): JsonResponse
    {
        return new JsonResponse(['id' => $this->submissionId], Response::HTTP_OK);
    }
}
