<?php

declare(strict_types=1);

namespace App\Application\Jobs\Shopwired;

use App\Application\Jobs\Enums\QueueName;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\ValueObjects\IntId;
use Closure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Base class for ShopWired entity sync jobs.
 *
 * Provides the shared error-handling, retry, and logging algorithm
 * for ShopWired entity sync jobs. Subclasses supply all work (fetch + save)
 * as a Closure to withErrorHandling(), which provides the error envelope.
 */
abstract class AbstractSyncShopwiredEntityJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

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

    /**
     * Execute the given work within the shared error-handling envelope.
     *
     * The Closure encapsulates all work (fetch + save). This method provides
     * uniform retry/fail logic for transient and permanent API failures.
     *
     * @param-immediately-invoked-callable $work
     *
     * @throws TransientApiFailure
     * @throws PermanentApiFailure
     * @throws Throwable
     */
    protected function withErrorHandling(LoggerInterface $logger, Closure $work): void
    {
        $context = [$this->contextKey() => $this->entityId->value];

        try {
            $work();

            $logger->info("{$this->entityLabel()} sync complete", $context);
        } catch (TransientApiFailure $e) {
            $logger->warning("{$this->entityLabel()} sync service unavailable, will retry", [
                ...$context,
                'retry_after' => $e->retryAfter,
                'attempts' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (PermanentApiFailure $e) {
            $this->fail($e);
            throw $e;
        } catch (Throwable $e) {
            $this->fail($e);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $context = [
            $this->contextKey() => $this->entityId->value,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error("{$this->entityLabel()} sync job failed permanently", $context);
        } else {
            Log::critical("{$this->entityLabel()} sync job failed permanently", $context);
        }
    }

    abstract protected function uniqueIdPrefix(): string;

    abstract protected function contextKey(): string;

    abstract protected function entityLabel(): string;
}
