<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use Psr\Log\LoggerInterface;

/**
 * Force-refreshes the stored connection so its rolling refresh-token expiry keeps moving while nothing else uses it.
 */
final readonly class UpdateQuickBooksConnectionTokenUseCase
{
    public function __construct(
        private QuickBooksConnectionRepositoryInterface $connections,
        private QuickBooksTokenService $tokens,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws AuthenticationExpiredException     when Intuit rejects the grant
     * @throws ExternalServiceUnavailableException
     * @throws LockAcquisitionException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    public function execute(): void
    {
        $this->logger->info('Updating QuickBooks connection token');

        if ($this->connections->find() === null) {
            $this->logger->info('No QuickBooks connection; token update skipped');

            return;
        }

        $this->tokens->forceRefresh();

        $this->logger->info('Updated QuickBooks connection token');
    }
}
