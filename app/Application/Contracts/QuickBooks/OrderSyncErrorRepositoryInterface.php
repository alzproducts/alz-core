<?php

declare(strict_types=1);

namespace App\Application\Contracts\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncErrorDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

/**
 * Append-only log of failed sync attempts.
 */
interface OrderSyncErrorRepositoryInterface
{
    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function append(string $orderSyncId, OrderSyncErrorDTO $error): void;
}
