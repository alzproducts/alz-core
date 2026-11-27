<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderCatchUpRunDTO;
use App\Application\QuickBooks\OrderSync\UseCases\HandleOrderSyncRunFailureUseCase;
use App\Application\QuickBooks\OrderSync\UseCases\RunCatchUpOrderSyncUseCase;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Support\Str;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Runs once, as the next hour's run is the recovery (ADR-0024). Unique, so a backed-up queue never runs two at once;
 * the lock lasts no longer than the schedule interval, so a lost lock cannot block later runs.
 */
final class SyncQuickBooksOrderCatchUpJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 1;

    public int $timeout = 300;

    /** @var array<int> Required by JobRequiredMethodsRule; unused with $tries=1 */
    public array $backoff = [];

    public int $uniqueFor = 3600;

    public readonly string $runId;

    public function __construct()
    {
        $this->runId = (string) Str::uuid();
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws KeyEncryptionFailedException
     * @throws LockAcquisitionException
     * @throws Throwable what queueing a chunk threw
     */
    public function handle(LoggerInterface $logger): void
    {
        // intentional: read before the use case is resolved, which builds the QuickBooks config and throws on blank
        // credentials; unset, the integration stays off (docs/adr/0015-blank-credentials-switch-integrations-off.md).
        $scheduleFrom = \config('quickbooks.order_sync_schedule_from');
        if ($scheduleFrom === null || $scheduleFrom === '') {
            $logger->info('Skipped QuickBooks order catch-up sync', ['reason' => 'switched_off']);

            return;
        }

        /** @var RunCatchUpOrderSyncUseCase $useCase */
        $useCase = \app(RunCatchUpOrderSyncUseCase::class);
        $useCase->execute($this->runId, CarbonImmutable::now()->toDateTimeImmutable());
    }

    public function failed(Throwable $exception): void
    {
        /** @var HandleOrderSyncRunFailureUseCase $useCase */
        $useCase = \app(HandleOrderSyncRunFailureUseCase::class);
        $useCase->execute(new OrderCatchUpRunDTO($this->runId), $exception);
    }
}
