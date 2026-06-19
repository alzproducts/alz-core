<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\Catalog;

use App\Application\Catalog\UseCases\SyncBestSellersCategoryUseCase;
use App\Domain\Catalog\CustomFields\Exceptions\InvalidCustomFieldValueException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\Exceptions\Api\ResourceNotFoundException;
use App\Domain\Exceptions\Data\MissingRequiredDataException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleDatabaseExceptions;
use DateTimeImmutable;
use Illuminate\Contracts\Queue\ShouldBeUnique;

/**
 * Daily orchestrator: query all ranked sellers and dispatch per-product Best Sellers
 * category membership updates for products whose membership needs to flip.
 *
 * Does NOT call the ShopWired API directly — only queries the database view
 * and dispatches UpdateProductBestSellersMembershipJob per product.
 */
final class SyncBestSellersCategoryJob extends AbstractJob implements ShouldBeUnique
{
    public int $tries = 3;

    public int $maxExceptions = 2;

    public int $timeout = 120;

    public int $uniqueFor = 3600;

    /** @var array<int> */
    public array $backoff = [30, 60];

    public function uniqueId(): string
    {
        return 'sync-best-sellers-category';
    }

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /** @return list<object> */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            new HandleDatabaseExceptions(),
        ];
    }

    public function retryUntil(): DateTimeImmutable
    {
        return \now()->addMinutes(45)->toDateTimeImmutable();
    }

    /**
     * @throws ResourceNotFoundException When Best Sellers category is inactive
     * @throws RecordNotFoundException When Best Sellers category row is missing in DB
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidCustomFieldValueException
     * @throws MissingRequiredDataException
     */
    public function handle(SyncBestSellersCategoryUseCase $useCase): void
    {
        $useCase->execute();
    }
}
