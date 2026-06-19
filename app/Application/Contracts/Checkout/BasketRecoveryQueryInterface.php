<?php

declare(strict_types=1);

namespace App\Application\Contracts\Checkout;

use App\Application\Checkout\DTOs\BasketRecoveryMatchDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

interface BasketRecoveryQueryInterface
{
    /**
     * @return list<BasketRecoveryMatchDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function getMatches(int $scopeWindowDays, bool $onlyNeedsUpdate): array;
}
