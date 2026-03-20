<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Shopwired;

use App\Infrastructure\Jobs\Enums\QueueName;
use App\Application\Shopwired\UseCases\CheckShopwiredWebhookHealthUseCase;
use App\Domain\Exceptions\Api\AbstractApiException;
use App\Domain\Exceptions\Api\PermanentApiFailure;
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
 * Daily health check for ShopWired webhook registrations.
 *
 * Delegates to CheckShopwiredWebhookHealthUseCase for business logic.
 * Handles queue-specific concerns: retry, backoff, and failure logging.
 */
final class ProcessShopwiredWebhookHealthJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 3;

    public int $maxExceptions = 3;

    public int $uniqueFor = 3600;

    /** @var array<int> */
    public array $backoff = [60, 300, 3600];

    public int $timeout = 60;

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return 'check-shopwired-webhook-health';
    }

    /**
     * @throws TransientApiFailure When ShopWired API unavailable (triggers retry)
     * @throws PermanentApiFailure When permanent API failure occurs (fails immediately)
     * @throws Throwable When unexpected errors occur — indicates code update required
     */
    public function handle(CheckShopwiredWebhookHealthUseCase $useCase, LoggerInterface $logger): void
    {
        try {
            $useCase->execute();
        } catch (TransientApiFailure $e) {
            $logger->warning('ShopWired webhook health check failed — API unavailable, will retry', [
                'service' => $e->serviceName,
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
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('ShopWired webhook health check job failed permanently', $context);
        } else {
            Log::critical('ShopWired webhook health check job failed permanently', $context);
        }
    }
}
