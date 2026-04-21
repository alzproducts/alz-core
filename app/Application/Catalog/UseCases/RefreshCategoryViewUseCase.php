<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\CategoryClientInterface;
use App\Application\Contracts\Shopwired\CategoryRepositoryInterface;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiRequestException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\ResourceNotAvailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\IntId;
use Psr\Log\LoggerInterface;

/**
 * Force-refresh a single category's data from ShopWired synchronously.
 */
final readonly class RefreshCategoryViewUseCase
{
    public function __construct(
        private CategoryClientInterface $client,
        private CategoryRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When category not found (404)
     * @throws ExternalServiceUnavailableException When ShopWired API or database unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws DatabaseOperationFailedException When category save fails
     * @throws DuplicateRecordException When unique constraint violated
     */
    public function execute(IntId $categoryId): void
    {
        $this->logger->info('Refreshing category', ['category_id' => $categoryId->value]);

        $category = $this->client->getCategoryById($categoryId->value);
        $this->repository->save($category);

        $this->logger->info('Category refresh complete', ['category_id' => $categoryId->value]);
    }
}
