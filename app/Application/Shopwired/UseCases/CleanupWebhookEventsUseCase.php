<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases;

use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Prune expired webhook idempotency records.
 *
 * Events older than the retention window have zero idempotency value
 * (ShopWired won't re-send webhooks from months ago), so they are pruned
 * to keep the webhook_events table from growing unboundedly.
 */
final readonly class CleanupWebhookEventsUseCase
{
    private const int RETENTION_DAYS = 90;

    public function __construct(
        private WebhookIdempotencyServiceInterface $idempotency,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(): void
    {
        $cutoff = new DateTimeImmutable(\sprintf('-%d days', self::RETENTION_DAYS));

        $this->logger->info('Starting webhook events cleanup', [
            'retention_days' => self::RETENTION_DAYS,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
        ]);

        $deleted = $this->idempotency->cleanup($cutoff);

        if ($deleted === 0) {
            $this->logger->warning('No expired webhook events found — expected rows beyond retention window', [
                'cutoff' => $cutoff->format('Y-m-d H:i:s'),
            ]);

            return;
        }

        $this->logger->info('Cleaned up expired webhook events', [
            'deleted_count' => $deleted,
            'cutoff' => $cutoff->format('Y-m-d H:i:s'),
        ]);
    }
}
