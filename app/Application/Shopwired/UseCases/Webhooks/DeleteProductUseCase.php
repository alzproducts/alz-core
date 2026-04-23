<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\ProductRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Handle `product.deleted` webhook events.
 *
 * Hard-deletes the product by external ID. Cascades to variations via FK constraint.
 * Idempotent — logs and returns silently if the product no longer exists.
 * No staleness check — delete events are fired once and have no safety net.
 */
final readonly class DeleteProductUseCase
{
    public function __construct(
        private ProductRepositoryInterface $productRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(int $webhookId, IntId $productId): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $productId->value];
        $this->logger->info('Processing product delete webhook', $context);

        try {
            $this->productRepository->deleteByExternalId($productId);
        } catch (RecordNotFoundException) {
            $this->logger->info('Product already deleted — skipping', $context);

            return;
        }

        $this->logger->info('Product deleted via webhook', $context);
    }
}
