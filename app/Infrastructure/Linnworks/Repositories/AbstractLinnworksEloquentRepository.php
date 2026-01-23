<?php

declare(strict_types=1);

namespace App\Infrastructure\Linnworks\Repositories;

use App\Application\Contracts\Linnworks\LinnworksRepositoryInterface;
use App\Infrastructure\Repositories\AbstractEloquentRepository;

/**
 * Abstract base class for Linnworks Eloquent repositories.
 *
 * Extends the common AbstractEloquentRepository with Linnworks-specific defaults:
 * - Identifier log key: 'linnworks_id'
 * - String GUID identifiers (vs ShopWired's integer IDs)
 *
 * @template T of object
 *
 * @extends AbstractEloquentRepository<T>
 * @implements LinnworksRepositoryInterface<T>
 */
abstract class AbstractLinnworksEloquentRepository extends AbstractEloquentRepository implements LinnworksRepositoryInterface
{
    /**
     * {@inheritDoc}
     *
     * Linnworks entities use 'linnworks_id' as the identifier log key.
     */
    protected function getIdentifierLogKey(): string
    {
        return 'linnworks_id';
    }
}
