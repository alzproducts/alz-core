<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Domain\Exceptions\InvalidConfigurationException;
use Illuminate\Support\ConfigurationUrlParser;

/**
 * The hosts a local or testing app may reach: loopback plus `LOCAL_ALLOWED_HOSTS` (`integrations.local_allowed_hosts`).
 */
final readonly class LocalHostAllowlist
{
    private const string DETAIL = 'Local and testing apps reach only loopback hosts or those listed in LOCAL_ALLOWED_HOSTS.';

    /**
     * @param array<mixed> $hosts
     */
    public function __construct(
        private array $hosts,
    ) {}

    /**
     * Whether a database or Redis connection resolves to an allowed host; its `url` wins, as in Laravel's own resolution.
     *
     * @param array<mixed> $connection
     */
    public function allowsConnection(array $connection): bool
    {
        /** @var array<string, mixed> $connection */
        $resolved = new ConfigurationUrlParser()->parseConfiguration($connection);

        return $this->allowsHost($resolved['host'] ?? null);
    }

    /**
     * @param array<mixed> $connection
     *
     * @throws InvalidConfigurationException Naming $urlKey when the connection has a url, else $hostKey
     */
    public function assertConnectionAllowed(array $connection, string $hostKey, string $urlKey): void
    {
        if ($this->allowsConnection($connection)) {
            return;
        }

        $url = $connection['url'] ?? null;

        throw new InvalidConfigurationException(\is_string($url) && $url !== '' ? $urlKey : $hostKey, self::DETAIL);
    }

    /**
     * An unset or empty URL is allowed: nothing can be reached through it.
     *
     * @throws InvalidConfigurationException Naming $configKey when the URL's host is not allowed
     */
    public function assertUrlAllowed(mixed $url, string $configKey): void
    {
        if (! \is_string($url) || $url === '' || $this->allowsHost(\parse_url($url, \PHP_URL_HOST))) {
            return;
        }

        throw new InvalidConfigurationException($configKey, self::DETAIL);
    }

    private function allowsHost(mixed $host): bool
    {
        return \is_string($host) && \in_array(\mb_trim($host, '[]'), $this->hosts, true);
    }
}
