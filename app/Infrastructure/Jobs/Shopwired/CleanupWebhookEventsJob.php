<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Shopwired\UseCases\CleanupWebhookEventsUseCase;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\TransientApiFailure;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
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

    public int $tries = 3;

    public int $maxExceptions = 3;

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

    /**
     * @throws TransientApiFailure When database unavailable (triggers retry)
     * @throws Throwable When unexpected errors occur — indicates code update required
     */
    public function handle(CleanupWebhookEventsUseCase $useCase, LoggerInterface $logger): void
    {
        try {
            $useCase->execute();
        } catch (TransientApiFailure $e) {
            $logger->warning('Webhook events cleanup failed — database unavailable, will retry', [
                'service' => $e->serviceName,
                'retry_after' => $e->retryAfter,
                'attempts' => $this->attempts(),
            ]);

            if ($e->retryAfter !== null) {
                $this->release($e->retryAfter);
            } else {
                throw $e;
            }
        } catch (Throwable $e) {
            $this->fail($e);
            throw $e;
        }
    }

    public function failed(Throwable $exception): void
    {
        $context = [
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('Webhook events cleanup job failed permanently', $context);
        } else {
            Log::critical('Webhook events cleanup job failed permanently', $context);
        }
    }
}
