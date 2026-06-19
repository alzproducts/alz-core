<?php

declare(strict_types=1);

namespace App\Presentation\Http\Checkout\Controllers;

use App\Application\Checkout\UseCases\GetBasketRecoveryMatchesUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Checkout\DTOs\BasketRecoveryRequestDTO;
use App\Presentation\Http\Checkout\Resources\BasketRecoveryMatchResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 * @throws MalformedStoredDataException
 */
final readonly class BasketRecoveryController
{
    public function __construct(
        private GetBasketRecoveryMatchesUseCase $useCase,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws MalformedStoredDataException
     */
    public function __invoke(BasketRecoveryRequestDTO $request): AnonymousResourceCollection
    {
        $matches = $this->useCase->execute(
            scopeWindowDays: $request->scopeWindow,
            onlyNeedsUpdate: $request->resolveOnlyNeedsUpdate(),
        );

        return BasketRecoveryMatchResource::collection($matches);
    }
}
