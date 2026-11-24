<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRunDTO;
use App\Application\QuickBooks\OrderSync\UseCases\HandleOrderSyncRunFailureUseCase;
use App\Application\QuickBooks\OrderSync\UseCases\RunOrderRangeSyncUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use Throwable;

/**
 * Runs once: a second run would dispatch a second set of chunks (ADR-0024).
 */
final class SyncQuickBooksOrderRangeJob extends AbstractJob
{
    public int $tries = 1;

    public int $timeout = 300;

    /** @var array<int> Required by JobRequiredMethodsRule; unused with $tries=1 */
    public array $backoff = [];

    public function __construct(
        public readonly OrderRangeRunDTO $run,
    ) {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function handle(RunOrderRangeSyncUseCase $useCase): void
    {
        $useCase->execute($this->run);
    }

    public function failed(Throwable $exception): void
    {
        /** @var HandleOrderSyncRunFailureUseCase $useCase */
        $useCase = \app(HandleOrderSyncRunFailureUseCase::class);
        $useCase->execute($this->run, $exception);
    }
}
