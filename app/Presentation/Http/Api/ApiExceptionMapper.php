<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api;

use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\Exceptions\DomainException;
use App\Domain\Exceptions\Infrastructure\ConfigurationNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\ValidationFailedException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Map domain exceptions to JSON HTTP responses for API routes.
 *
 * Registered in bootstrap/app.php via $exceptions->render().
 * Only activates when the request expects JSON (guards non-API routes).
 */
final class ApiExceptionMapper
{
    /**
     * Render a DomainException as a JSON response.
     *
     * Returns null for non-JSON requests to fall through to Laravel's default handler.
     */
    public static function render(DomainException $e, Request $request): ?JsonResponse
    {
        if (! $request->expectsJson()) {
            return null;
        }

        $status = self::statusCode($e);

        return new JsonResponse(
            ['message' => $e->getMessage()],
            $status,
        );
    }

    private static function statusCode(DomainException $e): int
    {
        return match (true) {
            $e instanceof ValidationFailedException => Response::HTTP_UNPROCESSABLE_ENTITY,
            $e instanceof ResourceNotFoundException => Response::HTTP_NOT_FOUND,
            $e instanceof TransientApiFailure => Response::HTTP_SERVICE_UNAVAILABLE,
            $e instanceof ConfigurationNotFoundException,
            $e instanceof DatabaseOperationFailedException => Response::HTTP_INTERNAL_SERVER_ERROR,
            default => Response::HTTP_INTERNAL_SERVER_ERROR,
        };
    }
}
