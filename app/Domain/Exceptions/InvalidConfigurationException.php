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
    public readonly string $detail;

    public function __construct(
        public readonly string $configKey,
        string $message = '',
    ) {
        $this->detail = $message;
        parent::__construct('Required configuration is missing or invalid');
    }

    /**
     * @return array<string, mixed>
     */
    public function context(): array
    {
        return \array_filter([
            'config_key' => $this->configKey,
            'detail' => $this->detail,
        ], static fn(string $value): bool => $value !== '');
    }
}
