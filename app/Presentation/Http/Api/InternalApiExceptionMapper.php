<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api;

use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\DomainException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Presentation\Http\Api\Responses\ApiErrorResponseDTO;
use App\Presentation\Http\Api\Responses\ApiErrorTypeEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\LaravelData\Exceptions\CannotCreateData;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Throwable;

/**
 * Universal JSON error envelope for the internal staff API.
 *
 * Maps any exception to a consistent {@see ApiErrorResponseDTO} shape.
 * Messages are safe for end users — no internal details are leaked.
 * Status codes are specific enough for ops triage from access logs alone.
 *
 * Registered in bootstrap/app.php via $exceptions->render().
 * Only activates for consumer API routes (/api/*) that expect JSON.
 * Non-API routes (e.g. /horizon/api/*) fall through to Laravel's default handler,
 * preserving headers like WWW-Authenticate needed for Basic Auth negotiation.
 */
final class InternalApiExceptionMapper
{
    /**
     * Render any exception as a JSON error envelope.
     *
     * Returns null for non-JSON requests or non-API routes to fall through to Laravel's default handler.
     */
    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson() || ! $request->is('api/*')) {
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
            // Domain exceptions (specific before catchall — order matters for inheritance)
            $e instanceof ValidationFailedException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $e instanceof ResourceNotFoundException => Response::HTTP_NOT_FOUND,
            $e instanceof DuplicateRecordException => Response::HTTP_CONFLICT,
            $e instanceof TransientApiFailure => Response::HTTP_SERVICE_UNAVAILABLE,
            $e instanceof LockAcquisitionException => Response::HTTP_SERVICE_UNAVAILABLE,
            $e instanceof PermanentApiFailure => Response::HTTP_BAD_GATEWAY,
            $e instanceof DomainException => Response::HTTP_INTERNAL_SERVER_ERROR,

            // Laravel / Spatie / Symfony exceptions
            $e instanceof CannotCreateData => Response::HTTP_UNPROCESSABLE_ENTITY,
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
            $e instanceof ValidationException,
            $e instanceof CannotCreateData => ApiErrorTypeEnum::ValidationError,
            $e instanceof ResourceNotFoundException,
            $e instanceof NotFoundHttpException => ApiErrorTypeEnum::NotFound,
            $e instanceof DuplicateRecordException => ApiErrorTypeEnum::Conflict,
            $e instanceof TransientApiFailure,
            $e instanceof LockAcquisitionException => ApiErrorTypeEnum::ServiceUnavailable,
            $e instanceof PermanentApiFailure => ApiErrorTypeEnum::UpstreamError,
            $e instanceof MethodNotAllowedHttpException => ApiErrorTypeEnum::MethodNotAllowed,
            $status === Response::HTTP_UNAUTHORIZED => ApiErrorTypeEnum::Unauthorized,
            $status === Response::HTTP_FORBIDDEN => ApiErrorTypeEnum::Forbidden,
            $status >= 500 => ApiErrorTypeEnum::ServerError,
            default => ApiErrorTypeEnum::Error,
        };
    }

    /**
     * User-facing message. Safe for end users — never leaks internals.
     *
     * Domain/HTTP/validation exceptions expose their own message (already safe).
     * Infrastructure and upstream failures use fixed generic messages.
     * Server errors (500+) always use a generic fallback.
     */
    private static function message(Throwable $e, int $status): string
    {
        // Fixed safe messages for infrastructure/upstream failures
        if ($e instanceof TransientApiFailure || $e instanceof LockAcquisitionException) {
            return 'The service is temporarily unavailable. Please try again shortly.';
        }

        if ($e instanceof PermanentApiFailure && ! $e instanceof ResourceNotFoundException) {
            return 'An upstream service encountered an error.';
        }

        if ($e instanceof DuplicateRecordException) {
            return 'A conflicting record already exists.';
        }

        if ($e instanceof CannotCreateData) {
            return 'The request data could not be processed.';
        }

        // Domain and HTTP exceptions expose their message directly
        if ($e instanceof DomainException || $e instanceof HttpException || $e instanceof ValidationException) {
            return $e->getMessage();
        }

        // Generic fallback for unrecognised exceptions — never leak internals
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
