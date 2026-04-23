<?php

declare(strict_types=1);

namespace App\Application\Shopwired\UseCases\Webhooks;

use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Handle `category.deleted` webhook events.
 *
 * Hard-deletes the category by external ID.
 * Idempotent — logs and returns silently if the category no longer exists.
 */
final readonly class DeleteCategoryUseCase
{
    public function __construct(
        private CategoryRepositoryInterface $categoryRepository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(int $webhookId, IntId $categoryId): void
    {
        $context = ['webhook_id' => $webhookId, 'subject_id' => $categoryId->value];
        $this->logger->info('Processing category delete webhook', $context);

        try {
            $this->categoryRepository->deleteByExternalId($categoryId);
        } catch (RecordNotFoundException) {
            $this->logger->info('Category already deleted — skipping', $context);

            return;
        }

        $this->logger->info('Category deleted via webhook', $context);
    }
}
