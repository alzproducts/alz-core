<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\CustomerRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Handle `customer.deleted` webhook events.
 *
 * Hard-deletes the customer by external ID.
 * Idempotent — logs and returns silently if the customer no longer exists.
 * No staleness check — delete events are fired once and have no safety net.
 */
final readonly class DeleteCustomerUseCase
{
    public function __construct(
        private CustomerRepositoryInterface $customerRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(int $webhookId, IntId $customerId): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $customerId->value];
        $this->logger->info('Processing customer delete webhook', $context);

        try {
            $this->customerRepository->deleteByExternalId($customerId);
        } catch (ResourceNotFoundException) {
            $this->logger->info('Customer already deleted — skipping', $context);

            return;
        }

        $this->logger->info('Customer deleted via webhook', $context);
    }
}
