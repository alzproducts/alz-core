<?php

declare(strict_types=1);

namespace App\Infrastructure\Exceptions;

/**
 * Base exception for all external API failures.
 *
 * Extends InfrastructureException to categorize API-specific failures
 * (HTTP errors, authentication issues, rate limits, timeouts) as a subset
 * of infrastructure concerns.
 *
 * API exceptions should capture service-specific context (service name,
 * status code, retry-after headers) and be translated to Domain exceptions
 * at the Infrastructure layer boundary before reaching Application layer.
 */
abstract class ApiException extends InfrastructureException {}
