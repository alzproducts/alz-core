<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Responses;

use Illuminate\Http\JsonResponse;

/**
 * Consistent JSON error envelope for all API responses.
 *
 * Guarantees the same top-level shape regardless of error origin:
 *
 *     {"error": {"type": "validation_error", "message": "...", "errors": {...}}}
 *
 * The `errors` key only appears on validation failures (field-level detail).
 */
final readonly class ApiErrorResponseDTO
{
    /**
     * @param array<string, mixed>|null $errors Field-level validation errors (null = omitted from response)
     */
    public function __construct(
        public ApiErrorTypeEnum $type,
        public string $message,
        public int $status,
        public ?array $errors = null,
    ) {}

    public function toJsonResponse(): JsonResponse
    {
        $body = [
            'type' => $this->type->value,
            'message' => $this->message,
        ];

        if ($this->errors !== null) {
            $body['errors'] = $this->errors;
        }

        return new JsonResponse(['error' => $body], $this->status);
    }
}
