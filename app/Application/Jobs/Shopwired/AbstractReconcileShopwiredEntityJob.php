<?php

declare(strict_types=1);

namespace App\Application\Jobs\Shopwired;

use App\Application\Contracts\RepositoryWriteInterface;
use App\Application\Jobs\Enums\QueueName;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
use App\Domain\Exceptions\Api\TransientApiFailure;
use App\Domain\ValueObjects\IntId;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Base class for ShopWired entity reconciliation jobs.
 *
 * Provides the shared error-handling, retry, and logging algorithm
 * for fetching an entity from the ShopWired API and persisting it locally.
 * Subclasses supply the entity-specific fetch logic via handle() DI
 * and pass the result into the shared algorithm.
 */
abstract class AbstractReconcileShopwiredEntityJob implements ShouldBeUnique, ShouldQueue
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
     * Execute the reconciliation algorithm with uniform error handling.
     *
     * @template TEntity of object
     *
     * @param TEntity $entity
     * @param RepositoryWriteInterface<TEntity> $repo
     *
     * @throws TransientApiFailure
     * @throws PermanentApiFailure
     * @throws Throwable
     */
    protected function executeSync(
        object $entity,
        RepositoryWriteInterface $repo,
        LoggerInterface $logger,
    ): void {
        $context = [$this->contextKey() => $this->entityId->value];

        try {
            $repo->save($entity);

            $logger->info("{$this->entityLabel()} reconciliation complete", $context);
        } catch (TransientApiFailure $e) {
            $logger->warning("{$this->entityLabel()} reconciliation service unavailable, will retry", [
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
            Log::error("{$this->entityLabel()} reconciliation job failed permanently", $context);
        } else {
            Log::critical("{$this->entityLabel()} reconciliation job failed permanently", $context);
        }
    }

    abstract protected function uniqueIdPrefix(): string;

    abstract protected function contextKey(): string;

    abstract protected function entityLabel(): string;
}
