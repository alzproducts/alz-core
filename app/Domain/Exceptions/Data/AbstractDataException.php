<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Data;

use App\Domain\Exceptions\DomainException;

/**
 * Marker base class for data validation/parsing exceptions.
 *
 * Enables type-based catching for all data exceptions:
 * - InvalidGtinException
 * - MalformedFeedDataException
 * - MissingRequiredDataException
 *
 * Usage:
 *   catch (AbstractDataException $e) { ... } // Catches all data validation failures
 */
abstract class AbstractDataException extends DomainException {}
