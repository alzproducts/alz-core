<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\CallTracking;

use App\Application\Contracts\Conversion\CallTracking\CallTrackingCallRepositoryInterface;
use App\Application\Conversion\CallTracking\UseCases\ProcessInboundCallUseCase;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Queue\Middleware\Skip;

/**
 * Process an inbound Twilio call notification.
 *
 * Exception Strategy:
 * - TransientApiFailure: {@see HandleApiExceptions} middleware (release/rethrow)
 * - PermanentApiFailure: {@see HandleApiExceptions} middleware (fail immediately)
 * - Already-complete calls: {@see Skip} middleware (call_sid + conversation_id check)
 */
final class ProcessInboundCallJob extends AbstractJob
{
    public int $tries = 6;

    public int $maxExceptions = 3;

    public int $timeout = 60;

    /** @var array<int> */
    public array $backoff = [60, 300, 3600];

    public function __construct(
        public readonly string $callSid,
        public readonly string $callerPhoneNumber,
        public readonly string $trackingNumberDialled,
    ) {
        $this->onQueue(QueueName::High->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            // @phpstan-ignore-next-line shipmonk.checkedExceptionInCallable (Skip invokes immediately; DB exceptions bubble to queue retry)
            Skip::when(fn(): bool => \app(CallTrackingCallRepositoryInterface::class)->isFullyProcessed($this->callSid)),
            ServiceCircuitBreaker::helpscout(),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws InsufficientDataException
     */
    public function handle(ProcessInboundCallUseCase $useCase): void
    {
        $useCase->execute(
            $this->callSid,
            $this->callerPhoneNumber,
            $this->trackingNumberDialled,
        );
    }
}
