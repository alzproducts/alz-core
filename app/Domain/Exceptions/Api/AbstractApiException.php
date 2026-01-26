<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Api;

use App\Domain\Exceptions\DomainException;

/**
 * Marker base class for API-related exceptions.
 *
 * Enables type-based catching for all API exceptions:
 * - AuthenticationExpiredException
 * - ExternalServiceUnavailableException
 * - InvalidApiRequestException
 * - InvalidApiResponseException
 * - PayloadSerializationException
 * - ResourceNotFoundException
 * - UnexpectedApiResultException
 *
 * Usage:
 *   catch (AbstractApiException $e) { ... } // Catches all API-related failures
 */
abstract class AbstractApiException extends DomainException {}
