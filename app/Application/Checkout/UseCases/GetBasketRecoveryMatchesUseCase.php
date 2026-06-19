<?php

declare(strict_types=1);

namespace App\Application\Checkout\UseCases;

use App\Application\Checkout\DTOs\BasketRecoveryMatchDTO;
use App\Application\Contracts\Checkout\BasketRecoveryQueryInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;

final readonly class GetBasketRecoveryMatchesUseCase
{
    public function __construct(
        private BasketRecoveryQueryInterface $recoveryQuery,
    ) {}

    /**
     * @return list<BasketRecoveryMatchDTO>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function execute(int $scopeWindowDays, bool $onlyNeedsUpdate): array
    {
        return $this->recoveryQuery->getMatches($scopeWindowDays, $onlyNeedsUpdate);
    }
}
