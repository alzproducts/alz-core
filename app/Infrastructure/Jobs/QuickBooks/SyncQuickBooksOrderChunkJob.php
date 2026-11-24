<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\OrderChunkRunDTO;
use App\Application\QuickBooks\OrderSync\UseCases\HandleOrderSyncRunFailureUseCase;
use App\Application\QuickBooks\OrderSync\UseCases\RunOrderChunkUseCase;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\QuickBooks\Dispatchers\QueuedOrderSyncDispatcher;
use App\Infrastructure\QuickBooks\QuickBooksOrderChunkSlot;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * Runs once; waits and re-attempts are fresh copies (ADR-0024).
 */
final class SyncQuickBooksOrderChunkJob extends AbstractJob
{
    /** Below the timeout, so there is room to record what a late call did and queue what is left. */
    public const int DEADLINE_SECONDS = 600;

    public int $tries = 1;

    public int $timeout = 900;

    /** @var array<int> Required by JobRequiredMethodsRule; unused with $tries=1 */
    public array $backoff = [];

    public function __construct(
        public readonly OrderChunkRunDTO $run,
    ) {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * @throws Throwable
     */
    public function handle(QuickBooksOrderChunkSlot $slot, RunOrderChunkUseCase $useCase, QueuedOrderSyncDispatcher $dispatcher): void
    {
        $ran = $slot->run(function () use ($useCase): void {
            $useCase->execute($this->run, Deadline::after(CarbonImmutable::now(), self::DEADLINE_SECONDS));
        });

        if (!$ran) {
            $dispatcher->dispatchWaiting($this->run);
        }
    }

    public function failed(Throwable $exception): void
    {
        /** @var HandleOrderSyncRunFailureUseCase $useCase */
        $useCase = \app(HandleOrderSyncRunFailureUseCase::class);
        $useCase->execute($this->run, $exception);
    }
}
