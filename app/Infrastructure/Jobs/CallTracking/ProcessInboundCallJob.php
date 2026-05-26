<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\CallTracking;

use App\Application\Conversion\CallTracking\UseCases\ProcessInboundCallUseCase;
use App\Domain\Exceptions\Data\InsufficientDataException;
use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\Exceptions\Data\MalformedStoredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\ValueObjects\Uuid;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use App\Infrastructure\Jobs\Middleware\ServiceCircuitBreaker;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Process an inbound Twilio call notification.
 *
 * Exception Strategy:
 * - TransientApiFailure: {@see HandleApiExceptions} middleware (release/rethrow)
 * - PermanentApiFailure: {@see HandleApiExceptions} middleware (fail immediately)
 * - InvalidFormatException: malformed inbound phone — fail immediately (won't resolve on retry)
 * - MalformedStoredDataException: corrupt stored phone — fail immediately (won't resolve on retry)
 */
final class ProcessInboundCallJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    public int $timeout = 60;

    /** @var array<int> */
    public array $backoff = [60, 300, 3600];

    public function __construct(
        public readonly string $callId,
        public readonly string $callerPhoneNumber,
        public readonly string $trackingNumberDialled,
        public readonly string $callSid,
    ) {
        $this->onQueue(QueueName::High->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
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
        try {
            $useCase->execute(
                Uuid::fromTrusted($this->callId),
                $this->callerPhoneNumber,
                $this->trackingNumberDialled,
                $this->callSid,
            );
        } catch (InvalidFormatException|MalformedStoredDataException $e) {
            $this->fail($e);
        }
    }
}
