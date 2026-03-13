<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Jobs\Shopwired\SyncShopwiredCustomerJob;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Handle `customer.updated` webhook events.
 *
 * Applies staleness and idempotency guards, persists the customer from the webhook
 * payload, records the webhook event, then queues a full API sync.
 */
final readonly class SyncCustomerUseCase
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private WebhookIdempotencyServiceInterface $idempotency,
        private LoggerInterface $logger,
        private int $webhookStalenessHours,
    ) {}

    /**
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(DateTimeImmutable $eventTime, int $webhookId, WebhookTopic $topic, Customer $customer, array $presentEmbeds = []): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $customer->id];
        $this->logger->info('Processing customer webhook', $context);

        $cutoff = (new DateTimeImmutable())->setTimestamp(\time() - ($this->webhookStalenessHours * 3600));

        if ($eventTime < $cutoff) {
            $this->logger->info('Discarding stale customer webhook', $context);

            return;
        }

        $customerId = IntId::from($customer->id);

        if ($this->idempotency->isSuperseded($customerId, $topic, $webhookId)) {
            $this->logger->info('Discarding already-processed customer webhook', $context);

            return;
        }

        $this->customerRepository->saveFromWebhook($customer, $presentEmbeds);
        $this->idempotency->record($customerId, $topic, $webhookId, $eventTime);

        SyncShopwiredCustomerJob::dispatch($customerId);

        $this->logger->info('Customer webhook processed — sync queued', $context);
    }
}
