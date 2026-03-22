<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Feeds;

use App\Application\Feeds\ProcessProductSearchFeedUseCase;
use App\Domain\Exceptions\Data\MalformedFeedDataException;
use App\Domain\Exceptions\Infrastructure\StorageOperationFailedException;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;
use DateTimeImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Process product search feed asynchronously.
 *
 * Fetches the source product feed, transforms XML (substitutes title with
 * display title), and uploads to cloud storage for site search consumption.
 * Scheduled daily at 1:00 AM UK time.
 */
final class ProcessProductSearchFeedJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $tries = 6;

    public int $maxExceptions = 3;

    public bool $failOnTimeout = true;

    /**
     * Seconds to wait before retrying.
     *
     * 1min, 5min, 1hr: quick retries catch transient issues, hour delay catches maintenance windows.
     *
     * @var list<int>
     */
    public array $backoff = [60, 300, 3600];

    /**
     * Job timeout in seconds (10 minutes).
     * Large feeds may take several minutes to process.
     */
    public int $timeout = 600;

    /**
     * Seconds the job can be uniquely locked.
     */
    public int $uniqueFor = 900;

    public function uniqueId(): string
    {
        return 'process-product-search-feed';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [new HandleApiExceptions()];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addHours(24)->toDateTimeImmutable();
    }

    /**
     * @throws StorageOperationFailedException
     */
    public function handle(ProcessProductSearchFeedUseCase $useCase): void
    {
        try {
            $useCase->execute();
        } catch (MalformedFeedDataException $e) {
            $this->fail($e);
        }
    }
}
