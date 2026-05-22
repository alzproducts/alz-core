<?php

declare(strict_types=1);

namespace App\Application\Contracts\Checkout;

use App\Application\Checkout\Commands\BasketSnapshotCommand;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Repository for pre-checkout basket snapshots.
 *
 * Handles persistence of immutable basket-state captures to the `checkout` schema.
 * Insert-only — snapshots are never updated.
 */
interface BasketSnapshotRepositoryInterface
{
    /**
     * Persist a new basket snapshot.
     *
     * @return string UUID of the created record
     *
     * @throws DatabaseOperationFailedException On insert failure
     * @throws DuplicateRecordException On unique constraint violation
     * @throws ExternalServiceUnavailableException On transient database failure
     */
    public function save(BasketSnapshotCommand $snapshot): string;
}
