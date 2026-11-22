<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncErrorRepositoryInterface;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncErrorDTO;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Persistence\EloquentGateway;
use App\Infrastructure\Persistence\Models\QuickBooks\OrderSyncErrorLogModel;
use Override;

final readonly class EloquentOrderSyncErrorRepository implements OrderSyncErrorRepositoryInterface
{
    public function __construct(
        private EloquentGateway $eloquentGateway,
    ) {}

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    #[Override]
    public function append(string $orderSyncId, OrderSyncErrorDTO $error): void
    {
        $this->eloquentGateway->insertMany(OrderSyncErrorLogModel::class, [[
            'order_sync_id' => $orderSyncId,
            'trigger' => $error->trigger->value,
            'run_id' => $error->runId,
            'reason_code' => $error->reasonCode,
            'qbo_fault_code' => $error->qboFaultCode,
            'qbo_fault_element' => $error->qboFaultElement,
            'message' => $error->message,
        ]]);
    }
}
