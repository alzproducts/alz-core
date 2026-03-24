<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\Responses;

use App\Presentation\Http\Api\Responses\ApiErrorResponseDTO;
use App\Presentation\Http\Api\Responses\ApiErrorTypeEnum;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(ApiErrorResponseDTO::class)]
final class ApiErrorResponseDTOTest extends TestCase
{
    #[Test]
    public function to_json_response_returns_correct_structure_with_type_and_message(): void
    {
        $dto = new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::NotFound,
            message: 'Resource not found',
            status: 404,
        );

        $response = $dto->toJsonResponse();
        $body = $response->getData(assoc: true);

        $this->assertSame(404, $response->getStatusCode());
        $this->assertSame('not_found', $body['error']['type']);
        $this->assertSame('Resource not found', $body['error']['message']);
    }

    #[Test]
    public function to_json_response_omits_errors_key_when_errors_is_null(): void
    {
        $dto = new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::ServerError,
            message: 'Something went wrong',
            status: 500,
            errors: null,
        );

        $response = $dto->toJsonResponse();
        $body = $response->getData(assoc: true);

        $this->assertArrayNotHasKey('errors', $body['error']);
    }

    #[Test]
    public function to_json_response_includes_errors_key_when_errors_is_provided(): void
    {
        $errors = ['field' => ['The field is required.']];

        $dto = new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::ValidationError,
            message: 'Validation failed',
            status: 422,
            errors: $errors,
        );

        $response = $dto->toJsonResponse();
        $body = $response->getData(assoc: true);

        $this->assertSame($errors, $body['error']['errors']);
    }

    #[Test]
    public function to_json_response_sets_correct_status_code(): void
    {
        $dto = new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::Conflict,
            message: 'Conflict',
            status: 409,
        );

        $response = $dto->toJsonResponse();

        $this->assertSame(409, $response->getStatusCode());
    }
}
