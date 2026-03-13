<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\ProductWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\ProductWebhookParserInterface;
use App\Application\Shopwired\Enums\WebhookTopic;
use App\Application\Shopwired\UseCases\Webhooks\DeleteProductUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncProductUseCase;
use App\Application\Shopwired\UseCases\Webhooks\UpdateProductStockUseCase;
use App\Domain\Catalog\Product\Enums\ProductWebhookIntent;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use DateTimeImmutable;

/**
 * Routes product webhook events to the appropriate use case.
 */
final readonly class HandleProductWebhookService
{
    public function __construct(
        private SyncProductUseCase $syncProductUseCase,
        private UpdateProductStockUseCase $updateProductStockUseCase,
        private DeleteProductUseCase $deleteProductUseCase,
        private ProductWebhookParserInterface $productParser,
        private ProductWebhookEventResolverInterface $resolver,
    ) {}

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException
     * @throws InvalidEnumValueException
     * @throws InvalidSkuException
     * @throws ResourceNotFoundException
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
        $webhookTopic = WebhookTopic::tryFrom($topic)
            ?? throw InvalidEnumValueException::invalidBackingValue(WebhookTopic::class, $topic);
        $productId = IntId::from($subjectId);

        match ($intent) {
            ProductWebhookIntent::Deleted => $this->deleteProductUseCase->execute(
                webhookId: $webhookId,
                productId: $productId,
            ),

            ProductWebhookIntent::StockChanged => $this->executeStockChanged(
                eventTime: $eventTime,
                webhookId: $webhookId,
                topic: $webhookTopic,
                productId: $productId,
                data: $data,
            ),

            ProductWebhookIntent::Sync => $this->executeSyncProduct(
                eventTime: $eventTime,
                webhookId: $webhookId,
                topic: $webhookTopic,
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
    private function executeSyncProduct(
        DateTimeImmutable $eventTime,
        int $webhookId,
        WebhookTopic $topic,
        array $data,
    ): void {
        $result = $this->productParser->parseProduct($data);

        $this->syncProductUseCase->execute(
            eventTime: $eventTime,
            webhookId: $webhookId,
            topic: $topic,
            product: $result->product,
            presentEmbeds: $result->presentEmbeds,
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidApiResponseException
     * @throws InvalidSkuException
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function executeStockChanged(
        DateTimeImmutable $eventTime,
        int $webhookId,
        WebhookTopic $topic,
        IntId $productId,
        array $data,
    ): void {
        $stockChange = $this->productParser->parseStockChange($data);

        $this->updateProductStockUseCase->execute(
            eventTime: $eventTime,
            webhookId: $webhookId,
            topic: $topic,
            productId: $productId,
            sku: Sku::fromString($stockChange->sku),
            isVariation: $stockChange->isVariation,
            newQuantity: $stockChange->newQuantity,
        );
    }
}
