<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\UseCases;

use App\Application\QuickBooks\Results\QuickBooksTokenLeaseResult;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use DateTimeInterface;
use Psr\Log\LoggerInterface;

final readonly class LeaseQuickBooksAccessTokenUseCase
{
    public function __construct(
        private QuickBooksTokenService $tokens,
        private LoggerInterface $logger,
    ) {}

    /**
     * Null when there is no stored connection.
     *
     * @throws AuthenticationExpiredException     when Intuit rejects the grant
     * @throws ExternalServiceUnavailableException
     * @throws LockAcquisitionException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    public function execute(): ?QuickBooksTokenLeaseResult
    {
        $this->logger->info('Leasing QuickBooks access token');

        $lease = $this->tokens->lease();

        if ($lease === null) {
            $this->logger->warning('No QuickBooks connection; access token not leased');

            return null;
        }

        $this->logger->info('Leased QuickBooks access token', [
            'expires_at' => $lease->expiresAt->format(DateTimeInterface::ATOM),
        ]);

        return $lease;
    }
}
