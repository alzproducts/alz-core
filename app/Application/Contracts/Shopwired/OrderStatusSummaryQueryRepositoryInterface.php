<?php

declare(strict_types=1);

namespace App\Application\Contracts\Shopwired;

use App\Application\Shopwired\DTOs\OrderStatusSummaryDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface OrderStatusSummaryQueryRepositoryInterface
{
    /**
     * Summary of unarchived orders, one per reference. Test customers' orders are included.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getSummary(): OrderStatusSummaryDTO;
}
