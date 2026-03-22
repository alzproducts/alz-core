<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\ValueObjects\IntId;
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
 * Base class for ShopWired entity sync jobs.
 *
 * Provides shared retry configuration, middleware, and failure logging
 * for ShopWired entity sync jobs. Error handling is delegated to
 * {@see HandleApiExceptions} middleware.
 */
abstract class AbstractSyncShopwiredEntityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    /** Lock duration in seconds — auto-releases if job gets stuck. */
    public int $uniqueFor = 300;

    /** @var array<int> */
    public array $backoff = [30, 60, 120];

    public int $timeout = 90;

    public function __construct(
        protected readonly IntId $entityId,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return $this->uniqueIdPrefix() . $this->entityId->value;
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
        return \now()->addHours(4)->toDateTimeImmutable();
    }

    abstract protected function uniqueIdPrefix(): string;

}
