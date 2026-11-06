<?php

declare(strict_types=1);

namespace App\Infrastructure\Integrations;

use Psr\Log\LoggerInterface;

/**
 * Logs a call a recording fake made instead of reaching its vendor.
 * intentional: ids only — fakes run in any non-production environment, so payload
 * fields (names, emails, phones, messages) never reach shipped logs.
 */
final readonly class OutboundCallRecorder
{
    public const string MESSAGE = 'Recorded outbound integration call';

    public function __construct(private LoggerInterface $logger) {}

    /**
     * @param array<string, int|string|null> $ids
     */
    public function record(LiveIntegration $integration, string $operation, array $ids = []): void
    {
        $this->logger->info(self::MESSAGE, [
            'integration' => $integration->value,
            'operation' => $operation,
            'ids' => $ids,
        ]);
    }
}
