<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\OrderWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\OrderWebhookParserInterface;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Application\Shopwired\UseCases\Webhooks\CreateOrderRefundUseCase;
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
use DateTimeImmutable;

/**
 * Routes order webhook events to the appropriate use case.
 */
final readonly class HandleOrderWebhookService
{
    public function __construct(
        private SyncOrderUseCase $syncOrderUseCase,
        private UpdateOrderStatusUseCase $updateStatusUseCase,
        private CreateOrderRefundUseCase $createRefundUseCase,
        private DeleteOrderUseCase $deleteOrderUseCase,
        private OrderWebhookParserInterface $orderParser,
        private OrderWebhookEventResolverInterface $resolver,
    ) {}

    /**
     * @param array<string, mixed> $data
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws InvalidApiResponseException
     * @throws InvalidEnumValueException
     * @throws ExternalServiceUnavailableException
     * @throws ResourceNotFoundException
     */
    public function execute(
        DateTimeImmutable $eventTime,
        int $webhookId,
        string $topic,
        int $subjectId,
        array $data,
    ): void {
        $intent = $this->resolver->resolve($topic);
        $webhookTopic = WebhookTopic::tryFrom($topic)
            ?? throw InvalidEnumValueException::invalidBackingValue(WebhookTopic::class, $topic);
        $orderId = IntId::from($subjectId);

        match ($intent) {
            OrderWebhookIntent::Deleted => $this->deleteOrderUseCase->execute(
                webhookId: $webhookId,
                orderId: $orderId,
            ),

            OrderWebhookIntent::StatusChanged => $this->updateStatusUseCase->execute(
                eventTime: $eventTime,
                webhookId: $webhookId,
                topic: $webhookTopic,
                orderId: $orderId,
                status: $this->orderParser->parseOrderStatus($data),
            ),

            OrderWebhookIntent::RefundCreated => $this->handleRefundCreated(
                eventTime: $eventTime,
                webhookId: $webhookId,
                data: $data,
            ),

            OrderWebhookIntent::Sync => $this->syncOrderUseCase->execute(
                eventTime: $eventTime,
                webhookId: $webhookId,
                topic: $webhookTopic,
                order: $this->orderParser->parseOrder($data),
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
    private function handleRefundCreated(DateTimeImmutable $eventTime, int $webhookId, array $data): void
    {
        $result = $this->orderParser->parseOrderRefund($data);

        $this->createRefundUseCase->execute(
            eventTime: $eventTime,
            webhookId: $webhookId,
            orderId: $result->orderId,
            refund: $result->refund,
        );
    }
}
