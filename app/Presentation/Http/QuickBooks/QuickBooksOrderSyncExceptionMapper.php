<?php

declare(strict_types=1);

namespace App\Presentation\Http\QuickBooks;

use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Domain\QuickBooks\Exceptions\QuickBooksMappingsUnresolvedException;
use App\Presentation\Http\Api\Responses\ApiErrorResponseDTO;
use App\Presentation\Http\Api\Responses\ApiErrorTypeEnum;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * The 412 preconditions of the order-sync routes only: the same exceptions keep their
 * InternalApiExceptionMapper status on every other route.
 *
 * Two 412s share a status and differ by `errors.missing_mapping_keys`: absent means QuickBooks is not connected
 * (no connection, blank credentials or a revoked grant), present means a seeded mapping key has no mapping.
 * The generic 503 (transient failure or lock) does not reveal its cause, so a client must not infer one; a 502 is
 * a permanent QuickBooks failure that a retry will not clear.
 */
final class QuickBooksOrderSyncExceptionMapper
{
    private const string SERVICE_NAME = 'QuickBooks';

    /**
     * @var list<string>
     */
    private const array CONNECTION_KEYS = [
        'QUICKBOOKS_CLIENT_ID',
        'QUICKBOOKS_CLIENT_SECRET',
        'QUICKBOOKS_REDIRECT_URI',
        'QUICKBOOKS_REALM_ID',
    ];

    public static function render(Throwable $e, Request $request): ?JsonResponse
    {
        if (!$request->routeIs('quickbooks.orders.*')) {
            return null;
        }

        if ($e instanceof QuickBooksMappingsUnresolvedException) {
            return self::preconditionFailed(
                'QuickBooks mappings are not complete.',
                ['missing_mapping_keys' => $e->missingKeys],
            );
        }

        return self::needsReconnect($e)
            ? self::preconditionFailed('QuickBooks is not connected. Reconnect it and try again.')
            : null;
    }

    private static function needsReconnect(Throwable $e): bool
    {
        return $e instanceof CorruptApiKeyException
            || ($e instanceof AuthenticationExpiredException && $e->serviceName === self::SERVICE_NAME)
            || ($e instanceof InvalidConfigurationException && \in_array($e->configKey, self::CONNECTION_KEYS, true));
    }

    /**
     * @param array<string, mixed>|null $errors
     */
    private static function preconditionFailed(string $message, ?array $errors = null): JsonResponse
    {
        return new ApiErrorResponseDTO(
            type: ApiErrorTypeEnum::PreconditionFailed,
            message: $message,
            status: JsonResponse::HTTP_PRECONDITION_FAILED,
            errors: $errors,
        )->toJsonResponse();
    }
}
