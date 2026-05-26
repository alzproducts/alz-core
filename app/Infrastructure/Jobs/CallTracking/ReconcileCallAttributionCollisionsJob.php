<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\CallTracking;

use App\Application\Conversion\CallTracking\UseCases\DetectCallAttributionCollisionsUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Hourly sweep that reports calls matching more than one visit inside the
 * attribution window — the only path that surfaces dashboard-excluded rows
 * to operators.
 */
final class ReconcileCallAttributionCollisionsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    public int $timeout = 120;

    /** @var array<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 300;

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new HandleDatabaseExceptions()];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function handle(DetectCallAttributionCollisionsUseCase $useCase): void
    {
        $useCase->execute();
    }
}
