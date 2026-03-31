<?php

declare(strict_types=1);

namespace App\Infrastructure\Feeds;

use App\Domain\Exceptions\InvalidConfigurationException;

/**
 * Immutable configuration for the Doofinder product search feed processor.
 *
 * Validates all required config values at construction time (fail-fast).
 *
 * @template-pattern API Client Config Value Object
 */
final readonly class DoofinderConfig
{
    /**
     * @throws InvalidConfigurationException When required config values are missing or invalid
     */
    public function __construct(
        public string $sourceUrl,
        public string $storagePath,
        public string $storageDisk,
    ) {
        self::validateNotEmpty($sourceUrl, 'feeds.doofinder.source_url');
        self::validateNotEmpty($storagePath, 'feeds.doofinder.storage_path');
        self::validateNotEmpty($storageDisk, 'feeds.doofinder.storage_disk');
    }

    private static function validateNotEmpty(string $value, string $configKey): void
    {
        if ($value === '') {
            throw new InvalidConfigurationException(
                $configKey,
                "Product search feed config missing required key: {$configKey}",
            );
        }
    }
}
