<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\CustomerWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\CustomerWebhookParserInterface;
use App\Application\Shopwired\UseCases\Webhooks\DeleteCustomerUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncCustomerUseCase;
use App\Domain\Customer\Enums\CustomerWebhookIntent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Routes customer webhook events to the appropriate use case.
 */
final readonly class HandleCustomerWebhookService
{
    public function __construct(
        private SyncCustomerUseCase $syncCustomerUseCase,
        private DeleteCustomerUseCase $deleteCustomerUseCase,
        private CustomerWebhookParserInterface $customerParser,
        private CustomerWebhookEventResolverInterface $resolver,
    ) {}

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(
        DateTimeImmutable $eventTime,
        int $webhookId,
        string $topic,
        int $subjectId,
        array $data,
    ): void {
        $intent = $this->resolver->resolve($topic);
        $customerId = IntId::from($subjectId);

        match ($intent) {
            CustomerWebhookIntent::Deleted => $this->deleteCustomerUseCase->execute(
                webhookId: $webhookId,
                customerId: $customerId,
            ),

            CustomerWebhookIntent::Sync => $this->handleSync(
                eventTime: $eventTime,
                webhookId: $webhookId,
                data: $data,
            ),
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function handleSync(DateTimeImmutable $eventTime, int $webhookId, array $data): void
    {
        $result = $this->customerParser->parseCustomer($data);

        $this->syncCustomerUseCase->execute(
            eventTime: $eventTime,
            webhookId: $webhookId,
            customer: $result->customer,
            presentEmbeds: $result->presentEmbeds,
        );
    }
}
