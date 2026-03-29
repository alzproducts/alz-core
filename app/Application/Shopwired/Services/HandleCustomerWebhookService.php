<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\CustomerWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\CustomerWebhookParserInterface;
use App\Application\Shopwired\DTOs\RawWebhookPayloadDTO;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Application\Shopwired\UseCases\Webhooks\DeleteCustomerUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncCustomerUseCase;
use App\Domain\Customer\Enums\CustomerWebhookIntent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;

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
     * @throws InvalidApiResponseException
     * @throws InvalidEnumValueException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(RawWebhookPayloadDTO $payload): void
    {
        $intent = $this->resolver->resolve($payload->topic);
        $webhookTopic = WebhookTopic::tryFrom($payload->topic)
            ?? throw InvalidEnumValueException::invalidBackingValue(WebhookTopic::class, $payload->topic);
        $customerId = IntId::from($payload->subjectId);
        $context = new WebhookContextDTO($payload->eventTime, $payload->webhookId, $webhookTopic);

        match ($intent) {
            CustomerWebhookIntent::Deleted => $this->deleteCustomerUseCase->execute(
                webhookId: $payload->webhookId,
                customerId: $customerId,
            ),

            CustomerWebhookIntent::Sync => $this->handleSync(
                context: $context,
                data: $payload->data,
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
    private function handleSync(WebhookContextDTO $context, array $data): void
    {
        $result = $this->customerParser->parseCustomer($data);

        $this->syncCustomerUseCase->execute(
            context: $context,
            customer: $result->customer,
            presentEmbeds: $result->presentEmbeds,
        );
    }
}
