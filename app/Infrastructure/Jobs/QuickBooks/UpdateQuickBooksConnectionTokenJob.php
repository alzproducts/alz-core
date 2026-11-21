<?php

declare(strict_types=1);

namespace App\Infrastructure\Jobs\QuickBooks;

use App\Application\QuickBooks\UseCases\UpdateQuickBooksConnectionTokenUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Infrastructure\Jobs\AbstractJob;
use App\Infrastructure\Jobs\Enums\QueueName;
use App\Infrastructure\Jobs\Middleware\HandleApiExceptions;

/**
 * Keeps the QuickBooks refresh token's rolling expiry moving while nothing else uses the connection.
 */
final class UpdateQuickBooksConnectionTokenJob extends AbstractJob
{
    public int $tries = 3;

    /**
     * @var array<int>
     */
    public array $backoff = [60, 300];

    public int $timeout = 120;

    public function __construct()
    {
        $this->onQueue(QueueName::Low->value);
    }

    /**
     * @return list<object>
     */
    public function middleware(): array
    {
        return [
            ...parent::middleware(),
            new HandleApiExceptions(),
        ];
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws LockAcquisitionException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    public function handle(UpdateQuickBooksConnectionTokenUseCase $useCase): void
    {
        $useCase->execute();
    }
}
