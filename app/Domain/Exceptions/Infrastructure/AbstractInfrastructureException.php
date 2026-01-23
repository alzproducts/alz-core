<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Infrastructure;

use App\Domain\Exceptions\DomainException;

/**
 * Marker base class for infrastructure/system exceptions.
 *
 * Enables type-based catching for all infrastructure exceptions:
 * - ConfigurationNotFoundException
 * - DatabaseOperationFailedException
 * - DuplicateRecordException
 * - StockUpdateFailedException
 * - StorageOperationFailedException
 *
 * Usage:
 *   catch (AbstractInfrastructureException $e) { ... } // Catches all infrastructure failures
 */
abstract class AbstractInfrastructureException extends DomainException {}
