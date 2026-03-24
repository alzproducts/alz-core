<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api;

use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\DomainException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Presentation\Http\Api\Responses\ApiErrorResponseDTO;
use App\Presentation\Http\Api\Responses\ApiErrorTypeEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Universal JSON error envelope for the internal staff API.
 *
 * Maps any exception to a consistent {@see ApiErrorResponseDTO} shape.
 * Server errors use a generic message to avoid leaking internals.
 *
 * Registered in bootstrap/app.php via $exceptions->render().
 * Only activates when the request expects JSON (guards non-API routes).
 */
final class InternalApiExceptionMapper
{
    /**
     * Render any exception as a JSON error envelope.
     *
     * Returns null for non-JSON requests to fall through to Laravel's default handler.
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        $status = self::statusCode($e);

        return (new ApiErrorResponseDTO(
            type: self::errorType($e, $status),
            message: self::message($e, $status),
            status: $status,
            errors: self::validationErrors($e),
        ))->toJsonResponse();
    }

    private static function statusCode(Throwable $e): int
    {
        return match (true) {
            // Domain exceptions
            $e instanceof ValidationFailedException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $e instanceof ResourceNotFoundException => Response::HTTP_NOT_FOUND,
            $e instanceof TransientApiFailure => Response::HTTP_SERVICE_UNAVAILABLE,
            $e instanceof DomainException => Response::HTTP_INTERNAL_SERVER_ERROR,

            // Laravel / Symfony exceptions
            $e instanceof ValidationException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $e instanceof NotFoundHttpException => Response::HTTP_NOT_FOUND,
            $e instanceof MethodNotAllowedHttpException => Response::HTTP_METHOD_NOT_ALLOWED,
            $e instanceof HttpException => $e->getStatusCode(),

            // Catchall
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }

    private static function errorType(Throwable $e, int $status): ApiErrorTypeEnum
    {
        return match (true) {
            $e instanceof ValidationFailedException,
            $e instanceof ValidationException => ApiErrorTypeEnum::ValidationError,
            $e instanceof ResourceNotFoundException,
            $e instanceof NotFoundHttpException => ApiErrorTypeEnum::NotFound,
            $e instanceof TransientApiFailure => ApiErrorTypeEnum::ServiceUnavailable,
            $e instanceof MethodNotAllowedHttpException => ApiErrorTypeEnum::MethodNotAllowed,
            $status >= 500 => ApiErrorTypeEnum::ServerError,
            default => ApiErrorTypeEnum::Error,
        };
    }

    /**
     * User-facing message. Generic for 500s to avoid leaking internals.
     */
    private static function message(Throwable $e, int $status): string
    {
        if ($e instanceof TransientApiFailure) {
            return 'The service is temporarily unavailable. Please try again shortly.';
        }

        // Domain and HTTP exceptions expose their message directly
        if ($e instanceof DomainException || $e instanceof HttpException || $e instanceof ValidationException) {
            return $e->getMessage();
        }

        if ($status >= Response::HTTP_INTERNAL_SERVER_ERROR) {
            return 'An unexpected error occurred.';
        }

        return $e->getMessage();
    }

    /**
     * Extract field-level validation errors when available.
     *
     * @return array<string, mixed>|null
     */
    private static function validationErrors(Throwable $e): ?array
    {
        if ($e instanceof ValidationException) {
            /** @var array<string, mixed> */
            return $e->errors();
        }

        if ($e instanceof ValidationFailedException && $e->context !== []) {
            return $e->context;
        }

        return null;
    }
}
