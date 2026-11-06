<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use App\Domain\Exceptions\InvalidConfigurationException;

/**
 * Decides whether an integration may reach its real vendor: always in production,
 * otherwise only when its `*_LIVE` opt-in is true (ADR-0015).
 */
final readonly class LiveIntegrationGate
{
    /**
     * @param array<string, mixed> $optIns `integrations.live`: true, false, or null for an unparseable value
     */
    public function __construct(
        private string $environment,
        private array $optIns,
    ) {}

    /**
     * @throws InvalidConfigurationException When the integration's opt-in value does not parse as a boolean
     */
    public function isLive(LiveIntegration $integration): bool
    {
        if ($this->environment === 'production') {
            return true;
        }

        $flag = \array_key_exists($integration->value, $this->optIns) ? $this->optIns[$integration->value] : false;

        if (! \is_bool($flag)) {
            throw $this->failClosed($integration);
        }

        return $flag;
    }

    public function failClosed(LiveIntegration $integration): InvalidConfigurationException
    {
        return new InvalidConfigurationException(
            $integration->optInSetting(),
            'Live calls to this integration are disabled outside production. Set ' . $integration->optInSetting() . '=true to opt in.',
        );
    }
}
