<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Application\Contracts\Shopwired\ShopwiredSyncDispatcherInterface;
use App\Application\Contracts\Shopwired\WebhookIdempotencyServiceInterface;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
use App\Domain\Customer\ValueObjects\Customer;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Override;
use Psr\Log\LoggerInterface;

/**
 * Handle `customer.updated` webhook events.
 *
 * Applies staleness and idempotency guards, persists the customer from the webhook
 * payload, records the webhook event, then queues a full API sync.
 */
final readonly class SyncCustomerUseCase extends AbstractSyncEntityWebhookUseCase
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private ShopwiredSyncDispatcherInterface $dispatcher,
        WebhookIdempotencyServiceInterface $idempotency,
        LoggerInterface $logger,
        int $webhookStalenessHours,
    ) {
        parent::__construct($idempotency, $logger, $webhookStalenessHours);
    }

    /**
     * @param list<string> $presentEmbeds Embed names present in webhook payload
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(WebhookContextDTO $context, Customer $customer, array $presentEmbeds = []): void
    {
        $this->process($context, $customer->id, $customer, $presentEmbeds);
    }

    /**
     * @param list<string> $presentEmbeds
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    protected function saveEntity(object $entity, array $presentEmbeds): void
    {
        /** @var Customer $entity */
        $this->customerRepository->saveFromWebhook($entity, $presentEmbeds);
    }

    #[Override]
    protected function dispatchSyncJob(IntId $entityId): void
    {
        $this->dispatcher->dispatchCustomerSync($entityId);
    }

    #[Override]
    protected function entityLabel(): string
    {
        return 'Customer';
    }
}
