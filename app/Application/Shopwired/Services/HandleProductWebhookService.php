<?php

declare(strict_types=1);

namespace App\Application\Shopwired\Services;

use App\Application\Contracts\Shopwired\ProductWebhookEventResolverInterface;
use App\Application\Contracts\Shopwired\ProductWebhookParserInterface;
use App\Application\Shopwired\UseCases\Webhooks\DeleteProductUseCase;
use App\Application\Shopwired\UseCases\Webhooks\SyncProductUseCase;
use App\Application\Shopwired\UseCases\Webhooks\UpdateProductStockUseCase;
use App\Domain\Catalog\Product\Enums\ProductWebhookIntent;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
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
        $productId = IntId::from($subjectId);

        match ($intent) {
            ProductWebhookIntent::Deleted => $this->deleteProductUseCase->execute(
                webhookId: $webhookId,
                productId: $productId,
            ),

            ProductWebhookIntent::StockChanged => $this->executeStockChanged(
                eventTime: $eventTime,
                webhookId: $webhookId,
                productId: $productId,
                data: $data,
            ),

            ProductWebhookIntent::Sync => $this->syncProductUseCase->execute(
                eventTime: $eventTime,
                webhookId: $webhookId,
                product: $this->productParser->parseProduct($data),
            ),
        };
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws InvalidSkuException
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function executeStockChanged(
        DateTimeImmutable $eventTime,
        int $webhookId,
        IntId $productId,
        array $data,
    ): void {
        /** @var array{sku: string, is_variation: bool, new_quantity: int} $data */
        $this->updateProductStockUseCase->execute(
            eventTime: $eventTime,
            webhookId: $webhookId,
            productId: $productId,
            sku: Sku::fromString($data['sku']),
            isVariation: $data['is_variation'],
            newQuantity: $data['new_quantity'],
        );
    }
}
