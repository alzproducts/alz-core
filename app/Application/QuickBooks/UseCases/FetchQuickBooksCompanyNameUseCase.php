<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksClientInterface;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;

/**
 * Reads the connected company's name: the end-to-end proof that the stored connection works.
 */
final readonly class FetchQuickBooksCompanyNameUseCase
{
    private const int DEADLINE_SECONDS = 30;

    public function __construct(
        private QuickBooksTokenService $tokens,
        private QuickBooksClientInterface $client,
        private LoggerInterface $logger,
    ) {}

    /**
     * @return non-empty-string
     *
     * @throws AuthenticationExpiredException      when there is no connection, or Intuit rejects the grant or token
     * @throws ExternalServiceUnavailableException
     * @throws QuickBooksDeadlineExceededException
     * @throws QuickBooksFaultException
     * @throws UnexpectedApiResultException
     * @throws LockAcquisitionException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    public function execute(): string
    {
        $this->logger->info('Fetching QuickBooks company name');
        $accessToken = $this->tokens->accessToken();

        try {
            $name = $this->client->companyName($accessToken, Deadline::after(new DateTimeImmutable(), self::DEADLINE_SECONDS));
        } catch (AuthenticationExpiredException $e) {
            // A rejected token must not be served from cache again; the next call refreshes.
            $this->tokens->forgetAccessToken();

            throw $e;
        }

        $this->logger->info('Fetched QuickBooks company name');

        return $name;
    }
}
