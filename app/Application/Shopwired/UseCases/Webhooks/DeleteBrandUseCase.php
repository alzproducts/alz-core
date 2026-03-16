<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Handle `brand.deleted` webhook events.
 *
 * Hard-deletes the brand by external ID.
 * Idempotent — logs and returns silently if the brand no longer exists.
 */
final readonly class DeleteBrandUseCase
{
    public function __construct(
        private BrandRepositoryInterface $brandRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(int $webhookId, IntId $brandId): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $brandId->value];
        $this->logger->info('Processing brand delete webhook', $context);

        try {
            $this->brandRepository->deleteByExternalId($brandId);
        } catch (ResourceNotFoundException) {
            $this->logger->info('Brand already deleted — skipping', $context);

            return;
        }

        $this->logger->info('Brand deleted via webhook', $context);
    }
}
