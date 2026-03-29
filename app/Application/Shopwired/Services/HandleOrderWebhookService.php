<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\OrderWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\OrderWebhookParserInterface;
use App\Application\Shopwired\DTOs\RawWebhookPayloadDTO;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Application\Shopwired\UseCases\Webhooks\CreateOrderRefundUseCase;
use App\Application\Shopwired\UseCases\Webhooks\DeleteOrderRefundUseCase;
use App\Application\Shopwired\UseCases\Webhooks\DeleteOrderUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncOrderUseCase;
use App\Application\Shopwired\UseCases\Webhooks\UpdateOrderStatusUseCase;
use App\Domain\Catalog\Order\Enums\OrderWebhookIntent;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;

/**
 * Routes order webhook events to the appropriate use case.
 */
final readonly class HandleOrderWebhookService
{
    public function __construct(
        private SyncOrderUseCase $syncOrderUseCase,
        private UpdateOrderStatusUseCase $updateStatusUseCase,
        private CreateOrderRefundUseCase $createRefundUseCase,
        private DeleteOrderRefundUseCase $deleteRefundUseCase,
        private DeleteOrderUseCase $deleteOrderUseCase,
        private OrderWebhookParserInterface $orderParser,
        private OrderWebhookEventResolverInterface $resolver,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws InvalidApiResponseException
     * @throws InvalidEnumValueException
     * @throws ExternalServiceUnavailableException
     * @throws ResourceNotFoundException
     */
    public function execute(RawWebhookPayloadDTO $payload): void
    {
        $intent = $this->resolver->resolve($payload->topic);
        $webhookTopic = WebhookTopic::tryFrom($payload->topic)
            ?? throw InvalidEnumValueException::invalidBackingValue(WebhookTopic::class, $payload->topic);
        $orderId = IntId::from($payload->subjectId);
        $context = new WebhookContextDTO($payload->eventTime, $payload->webhookId, $webhookTopic);

        match ($intent) {
            OrderWebhookIntent::Deleted => $this->deleteOrderUseCase->execute(
                webhookId: $payload->webhookId,
                orderId: $orderId,
            ),

            OrderWebhookIntent::StatusChanged => $this->updateStatusUseCase->execute(
                context: $context,
                orderId: $orderId,
                status: $this->orderParser->parseOrderStatus($payload->data),
            ),

            OrderWebhookIntent::RefundCreated => $this->handleRefundCreated(
                context: $context,
                data: $payload->data,
            ),

            OrderWebhookIntent::RefundDeleted => $this->deleteRefundUseCase->execute(
                webhookId: $payload->webhookId,
                orderId: $orderId,
                refundExternalId: $this->orderParser->parseRefundExternalId($payload->data),
            ),

            OrderWebhookIntent::Sync => $this->syncOrderUseCase->execute(
                context: $context,
                order: $this->orderParser->parseOrder($payload->data),
            ),
        };
    }

    /**
     * Handle refund webhooks where subjectId is the refund ID, not the order ID.
     *
     * @param array<string, mixed> $data
     *
     * @throws DatabaseOperationFailedException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws ResourceNotFoundException
     */
    private function handleRefundCreated(WebhookContextDTO $context, array $data): void
    {
        $result = $this->orderParser->parseOrderRefund($data);

        $this->createRefundUseCase->execute(
            eventTime: $context->eventTime,
            webhookId: $context->webhookId,
            orderId: $result->orderId,
            refund: $result->refund,
        );
    }
}
