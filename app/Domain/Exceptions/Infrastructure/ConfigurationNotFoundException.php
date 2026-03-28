<?php

declare(strict_types=1);

namespace App\Domain\Exceptions\Infrastructure;

use Throwable;

/**
 * Thrown when required configuration is not found or disabled.
 *
 * Use cases:
 * - Required database config row missing
 * - Config exists but is disabled (enabled=false)
 *
 * This is a permanent error until configuration is fixed.
 */
final class ConfigurationNotFoundException extends AbstractInfrastructureException
{
    public function __construct(
        public readonly string $configName,
        ?Throwable $previous = null,
    ) {
        parent::__construct('Required configuration not found', 0, $previous);
    }

    public function context(): array
    {
        return ['config_name' => $this->configName];
    }
}
