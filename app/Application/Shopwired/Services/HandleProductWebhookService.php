<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\ProductWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\ProductWebhookParserInterface;
use App\Application\Shopwired\DTOs\RawWebhookPayloadDTO;
use App\Application\Shopwired\DTOs\StockChangeDataDTO;
use App\Application\Shopwired\DTOs\WebhookContextDTO;
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
     * @throws InvalidApiResponseException
     * @throws InvalidEnumValueException
     * @throws InvalidSkuException
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(RawWebhookPayloadDTO $payload): void
    {
        $intent = $this->resolver->resolve($payload->topic);
        $webhookTopic = WebhookTopic::tryFrom($payload->topic)
            ?? throw InvalidEnumValueException::invalidBackingValue(WebhookTopic::class, $payload->topic);
        $productId = IntId::from($payload->subjectId);
        $context = new WebhookContextDTO($payload->eventTime, $payload->webhookId, $webhookTopic);

        match ($intent) {
            ProductWebhookIntent::Deleted => $this->deleteProductUseCase->execute(
                webhookId: $payload->webhookId,
                productId: $productId,
            ),

            ProductWebhookIntent::StockChanged => $this->executeStockChanged(
                context: $context,
                productId: $productId,
                data: $payload->data,
            ),

            ProductWebhookIntent::Sync => $this->executeSyncProduct(
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
    private function executeSyncProduct(WebhookContextDTO $context, array $data): void
    {
        $result = $this->productParser->parseProduct($data);

        $this->syncProductUseCase->execute(
            context: $context,
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
    private function executeStockChanged(WebhookContextDTO $context, IntId $productId, array $data): void
    {
        $stockChange = $this->productParser->parseStockChange($data);

        $this->updateProductStockUseCase->execute(
            context: $context,
            data: new StockChangeDataDTO(
                productId: $productId,
                sku: Sku::fromString($stockChange->sku),
                isVariation: $stockChange->isVariation,
                newQuantity: $stockChange->newQuantity,
            ),
        );
    }
}
