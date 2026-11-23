<?php

declare(strict_types=1);

namespace App\Presentation\Http\Internal\Controllers;

use App\Application\Shopwired\UseCases\GetOrderStatusSummaryUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Presentation\Http\Internal\Resources\OrderStatusSummaryResource;

/**
 * @throws DatabaseOperationFailedException
 * @throws DuplicateRecordException
 * @throws ExternalServiceUnavailableException
 */
final readonly class OrderStatusSummaryController
{
    public function __construct(
        private GetOrderStatusSummaryUseCase $useCase,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function __invoke(): OrderStatusSummaryResource
    {
        return new OrderStatusSummaryResource($this->useCase->execute());
    }
}
