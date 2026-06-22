<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Catalog\UseCases\SyncProductsViewUseCase;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use Illuminate\Contracts\Queue\ShouldBeUnique;

final class SyncProductsViewJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 30;

    public int $uniqueFor = 60;

    /** @var array<int> */
    public array $backoff = [5, 10];

    public function uniqueId(): string
    {
        return 'sync-products-view';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Default->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            new HandleDatabaseExceptions(),
        ];
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    public function handle(SyncProductsViewUseCase $useCase): void
    {
        $useCase->execute();
    }
}
