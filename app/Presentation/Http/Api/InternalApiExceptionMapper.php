<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api;

use App\Domain\Catalog\CustomFields\Exceptions\ProductSettingsNotApplicableException;
use App\Domain\ContactSubmission\Exceptions\InvalidActionStageException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\MissingApiKeyException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\DomainException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\Inventory\InvalidTemplateException;
use App\Domain\Exceptions\ValidationFailedException;
use App\Presentation\Http\Api\Middleware\ForceJsonResponseMiddleware;
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
 * {@see ForceJsonResponseMiddleware} guarantees `expectsJson()` is true for
 * all /api/* requests — the guard below is retained as defence-in-depth for
 * non-API routes (e.g. /horizon/api/*) where the middleware is a no-op,
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

    /**
     * Note: RecordNotFoundException must precede TransientApiFailure in the match arms
     * below — it extends TransientApiFailure but needs 404, not 503.
     */
    private static function statusCode(Throwable $e): int
    {
        return self::domainStatusCode($e)
            ?? self::frameworkStatusCode($e)
            ?? Response::HTTP_INTERNAL_SERVER_ERROR;
    }

    private static function domainStatusCode(Throwable $e): ?int
    {
        if (self::isValidationException($e)) {
            return Response::HTTP_UNPROCESSABLE_ENTITY;
        }

        return match (true) {
            $e instanceof ResourceNotFoundException,
            $e instanceof RecordNotFoundException => Response::HTTP_NOT_FOUND,
            $e instanceof InvalidActionStageException,
            $e instanceof DuplicateRecordException => Response::HTTP_CONFLICT,
            $e instanceof TransientApiFailure,
            $e instanceof LockAcquisitionException => Response::HTTP_SERVICE_UNAVAILABLE,
            $e instanceof MissingApiKeyException,
            $e instanceof CorruptApiKeyException => Response::HTTP_PRECONDITION_FAILED,
            $e instanceof PermanentApiFailure => Response::HTTP_BAD_GATEWAY,
            $e instanceof DomainException => Response::HTTP_INTERNAL_SERVER_ERROR,
            default => null,
        };
    }

    private static function frameworkStatusCode(Throwable $e): ?int
    {
        return match (true) {
            $e instanceof CannotCreateData,
            $e instanceof ValidationException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $e instanceof NotFoundHttpException => Response::HTTP_NOT_FOUND,
            $e instanceof MethodNotAllowedHttpException => Response::HTTP_METHOD_NOT_ALLOWED,
            $e instanceof HttpException => $e->getStatusCode(),
            default => null,
        };
    }

    private static function errorType(Throwable $e, int $status): ApiErrorTypeEnum
    {
        return self::errorTypeFromException($e) ?? self::errorTypeFromStatus($status);
    }

    /**
     * Note: RecordNotFoundException must precede TransientApiFailure in the match arms
     * below — it extends TransientApiFailure but needs NotFound, not ServiceUnavailable.
     */
    private static function errorTypeFromException(Throwable $e): ?ApiErrorTypeEnum
    {
        if (self::isValidationException($e)) {
            return ApiErrorTypeEnum::ValidationError;
        }

        return match (true) {
            $e instanceof ResourceNotFoundException,
            $e instanceof RecordNotFoundException,
            $e instanceof NotFoundHttpException => ApiErrorTypeEnum::NotFound,
            $e instanceof InvalidActionStageException,
            $e instanceof DuplicateRecordException => ApiErrorTypeEnum::Conflict,
            $e instanceof TransientApiFailure,
            $e instanceof LockAcquisitionException => ApiErrorTypeEnum::ServiceUnavailable,
            $e instanceof CorruptApiKeyException => ApiErrorTypeEnum::CipherCorrupted,
            $e instanceof MissingApiKeyException => ApiErrorTypeEnum::PreconditionFailed,
            $e instanceof PermanentApiFailure => ApiErrorTypeEnum::UpstreamError,
            $e instanceof MethodNotAllowedHttpException => ApiErrorTypeEnum::MethodNotAllowed,
            default => null,
        };
    }

    private static function isValidationException(Throwable $e): bool
    {
        return $e instanceof ValidationFailedException
            || $e instanceof InvalidSkuException
            || $e instanceof InvalidTemplateException
            || $e instanceof MissingRequiredDataException
            || $e instanceof InsufficientDataException
            || $e instanceof ProductSettingsNotApplicableException
            || $e instanceof ValidationException
            || $e instanceof CannotCreateData;
    }

    private static function errorTypeFromStatus(int $status): ApiErrorTypeEnum
    {
        return match (true) {
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
        $fixedMessage = self::fixedSafeMessage($e);
        if ($fixedMessage !== null) {
            return $fixedMessage;
        }

        if ($e instanceof DomainException || $e instanceof HttpException || $e instanceof ValidationException) {
            return $e->getMessage();
        }

        return $status >= Response::HTTP_INTERNAL_SERVER_ERROR
            ? 'An unexpected error occurred.'
            : $e->getMessage();
    }

    /**
     * Fixed safe messages for infrastructure failures — never leaks internals.
     */
    private static function fixedSafeMessage(Throwable $e): ?string
    {
        return match (true) {
            $e instanceof TransientApiFailure && ! $e instanceof RecordNotFoundException,
            $e instanceof LockAcquisitionException => 'The service is temporarily unavailable. Please try again shortly.',
            $e instanceof MissingApiKeyException => null,
            $e instanceof CorruptApiKeyException => 'Your stored API key cannot be read. Please re-paste it in Settings.',
            $e instanceof PermanentApiFailure && ! $e instanceof ResourceNotFoundException => 'An upstream service encountered an error.',
            $e instanceof DuplicateRecordException => 'A conflicting record already exists.',
            $e instanceof CannotCreateData => 'The request data could not be processed.',
            default => null,
        };
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

        if ($e instanceof ProductSettingsNotApplicableException || $e instanceof InvalidTemplateException) {
            return $e->context();
        }

        return null;
    }
}
