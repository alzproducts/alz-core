<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Application\Shopwired\UseCases\CleanupWebhookEventsUseCase;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\ThrottlesExceptions;
use Throwable;

/**
 * Weekly retention cleanup for the shopwired.webhook_events table.
 *
 * Delegates to CleanupWebhookEventsUseCase for business logic.
 * Handles queue-specific concerns: retry, backoff, and failure logging.
 */
final class CleanupWebhookEventsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    public int $uniqueFor = 3600;

    /** @var array<int> */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    public function uniqueId(): string
    {
        return 'cleanup-shopwired-webhook-events';
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            (new ThrottlesExceptions(maxAttempts: 10, decaySeconds: 300))
                ->by('shopwired')
                ->when(static fn(Throwable $e): bool => $e instanceof TransientApiFailure),
            new HandleApiExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    public function handle(CleanupWebhookEventsUseCase $useCase): void
    {
        $useCase->execute();
    }
}
