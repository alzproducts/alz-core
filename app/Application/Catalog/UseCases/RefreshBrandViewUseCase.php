<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Application\Contracts\Shopwired\BrandClientInterface;
use App\Application\Contracts\Shopwired\BrandRepositoryInterface;
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
 * Force-refresh a single brand's data from ShopWired synchronously.
 */
final readonly class RefreshBrandViewUseCase
{
    public function __construct(
        private BrandClientInterface $client,
        private BrandRepositoryInterface $repository,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws InvalidApiRequestException When request parameters are invalid (400)
     * @throws AuthenticationExpiredException When credentials invalid/expired (401/403)
     * @throws ResourceNotAvailableException When brand not found (404)
     * @throws ExternalServiceUnavailableException When ShopWired API or database unavailable
     * @throws InvalidApiResponseException When API response parsing fails
     * @throws DatabaseOperationFailedException When brand save fails
     * @throws DuplicateRecordException When unique constraint violated
     */
    public function execute(IntId $brandId): void
    {
        $this->logger->info('Refreshing brand', ['brand_id' => $brandId->value]);

        $brand = $this->client->getBrandById($brandId->value);
        $this->repository->save($brand);

        $this->logger->info('Brand refresh complete', ['brand_id' => $brandId->value]);
    }
}
