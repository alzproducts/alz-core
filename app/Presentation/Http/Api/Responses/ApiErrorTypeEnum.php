<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Responses;

/**
 * Machine-readable error type for frontend error handling.
 *
 * Sent in the `error.type` field of every JSON error response.
 * Frontend switches on this value to determine error display/recovery behaviour.
 */
enum ApiErrorTypeEnum: string
{
    case ValidationError = 'validation_error';
    case NotFound = 'not_found';
    case ServiceUnavailable = 'service_unavailable';
    case MethodNotAllowed = 'method_not_allowed';
    case ServerError = 'server_error';
    case Error = 'error';
}
