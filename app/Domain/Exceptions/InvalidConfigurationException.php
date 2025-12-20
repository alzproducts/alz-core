<?php

declare(strict_types=1);

namespace App\Domain\Exceptions;

use LogicException;

/**
 * Thrown when required configuration is missing or invalid.
 *
 * Use cases:
 * - Missing environment variables (not set, null)
 * - Invalid configuration values (wrong type, empty string)
 * - Invalid configuration structure (expected array, got scalar)
 *
 * Extends LogicException because configuration is developer-managed content.
 * Invalid config represents a deployment/setup error, not a runtime condition.
 * The code is correct; the configuration needs to be fixed.
 *
 * This exception is unchecked (LogicException family) - callers don't need
 * to explicitly handle it because it should never occur in properly deployed apps.
 */
final class InvalidConfigurationException extends LogicException
{
    public function __construct(
        public readonly string $configKey,
        string $message = '',
    ) {
        $defaultMessage = "Required configuration '{$configKey}' is missing or invalid";
        parent::__construct(($message !== '') ? $message : $defaultMessage);
    }
}
