<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use Psr\Log\LoggerInterface;

/**
 * Logs a call a stand-in swallowed instead of reaching its vendor.
 * intentional: ids only — stand-ins run in any non-production environment, so payload
 * fields (names, emails, phones, messages) never reach shipped logs.
 */
final readonly class StandInCallLogger
{
    public const string MESSAGE = 'Integration stand-in swallowed a call';

    public const string HELPSCOUT = 'helpscout';

    public const string SLACK = 'slack';

    public const string GOOGLE_ADS = 'google_ads';

    public const string BING_ADS = 'bing_ads';

    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param array<string, int|string|null> $ids
     */
    public function record(string $integration, string $operation, array $ids = []): void
    {
        $this->logger->info(self::MESSAGE, [
            'integration' => $integration,
            'operation' => $operation,
            'ids' => $ids,
        ]);
    }
}
