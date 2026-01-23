<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired\Repositories;

use App\Application\Contracts\Shopwired\ShopwiredRepositoryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Repositories\AbstractEloquentRepository;
use RuntimeException;

/**
 * Abstract base class for ShopWired Eloquent repositories.
 *
 * Extends the common AbstractEloquentRepository with ShopWired-specific defaults:
 * - Identifier log key: 'external_id'
 * - Convenience methods for external ID lookups
 *
 * @template T of object
 *
 * @extends AbstractEloquentRepository<T>
 * @implements ShopwiredRepositoryInterface<T>
 */
abstract class AbstractShopwiredEloquentRepository extends AbstractEloquentRepository implements ShopwiredRepositoryInterface
{
    protected function getIdentifierLogKey(): string
    {
        return 'external_id';
    }

    /**
     * Get entity by ShopWired's external ID.
     *
     * @return T
     *
     * @throws ResourceNotFoundException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws RuntimeException When model doesn't implement EloquentDomainMappableInterface (programming error)
     */
    public function getByExternalId(int $externalId): object
    {
        return $this->getByColumn($externalId, 'external_id');
    }

    /**
     * Check existence by ShopWired's external ID.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function existsByExternalId(int $externalId): bool
    {
        return $this->existsByColumn($externalId, 'external_id');
    }
}
