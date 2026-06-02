<?php

declare(strict_types=1);

namespace App\Application\Contracts\Catalog;

use App\Application\Catalog\Commands\CostPriceChangeCommand;
use App\Application\Contracts\RepositoryWriteInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Append-only write access to `catalog.cost_price_changes`.
 *
 * Not a domain-entity repository — it records an audit trail, so it deliberately does not extend
 * {@see RepositoryWriteInterface} (no save/find/delete identity semantics).
 */
interface CostPriceChangeLogRepositoryInterface
{
    /**
     * Persist a batch of cost-price change records. A no-op for an empty list.
     *
     * @param list<CostPriceChangeCommand> $changes
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function record(array $changes): void;
}
