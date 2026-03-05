<?php

declare(strict_types=1);

namespace App\Application\Jobs\Shopwired;

use App\Application\Contracts\Shopwired\OrderClientInterface;
use App\Application\Contracts\Shopwired\OrderRepositoryInterface;
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
 * Fetch the current state of a ShopWired order from the API and persist it.
 *
 * Dispatched by webhook use cases after a partial update. Ensures the local
 * record reflects the authoritative API state even if the webhook payload
 * was partial or arrived out of order.
 *
 * ShouldBeUnique: duplicate dispatches for the same order are discarded until
 * this job completes, preventing redundant API calls during webhook bursts.
 */
final class ReconcileShopwiredOrderJob implements ShouldBeUnique, ShouldQueue
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
        private readonly IntId $orderId,
    ) {
        $this->onQueue(QueueName::Default->value);
    }

    public function uniqueId(): string
    {
        return 'reconcile-shopwired-order-' . $this->orderId->value;
    }

    /**
     * @throws TransientApiFailure
     * @throws PermanentApiFailure
     * @throws Throwable
     */
    public function handle(
        OrderClientInterface $client,
        OrderRepositoryInterface $repo,
        LoggerInterface $logger,
    ): void {
        $context = ['order_id' => $this->orderId->value];

        try {
            $order = $client->getOrderById($this->orderId->value);
            $repo->save($order);

            $logger->info('Order reconciliation complete', $context);
        } catch (TransientApiFailure $e) {
            $logger->warning('Order reconciliation service unavailable, will retry', [
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
            'order_id' => $this->orderId->value,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'attempts' => $this->attempts(),
        ];

        if ($exception instanceof AbstractApiException) {
            Log::error('Order reconciliation job failed permanently', $context);
        } else {
            Log::critical('Order reconciliation job failed permanently', $context);
        }
    }
}
