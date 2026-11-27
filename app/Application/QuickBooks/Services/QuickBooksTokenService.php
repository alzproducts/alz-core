<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Services;

use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Application\Inventory\Enums\LockName;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Domain\QuickBooks\ValueObjects\QuickBooksTokenGrant;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Owns the QBO token lifecycle. Every refresh runs under the fail-closed token lock because Intuit
 * rotates the refresh token on use (ADR-0021).
 */
final readonly class QuickBooksTokenService
{
    private const string SERVICE_NAME = 'QuickBooks';

    public const int LOCK_TIMEOUT_SECONDS = 20;

    public function __construct(
        private QuickBooksOAuthClientInterface $oauthClient,
        private QuickBooksConnectionRepositoryInterface $connections,
        private QuickBooksAccessTokenCacheInterface $accessTokens,
        private LockManagerInterface $lockManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * A cached access token outlives a deleted connection, so a token alone does not prove one.
     *
     * @throws AuthenticationExpiredException when there is no connection
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     */
    public function assertConnected(): void
    {
        $this->storedConnection();
    }

    /**
     * The cached access token, refreshed under the lock when none is cached. A cached token is never returned
     * without a stored connection.
     *
     * @throws LockAcquisitionException
     * @throws AuthenticationExpiredException     when there is no connection or Intuit rejects it
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    public function accessToken(): ApiKeyToken
    {
        // intentional: this read only refuses a deleted connection; a refresh re-reads it under the lock (ADR-0021).
        $this->storedConnection();

        return $this->accessTokens->get() ?? $this->refreshUnderLock(force: false);
    }

    /**
     * Refreshes even when an access token is cached, so the refresh token's rolling expiry moves on.
     *
     * @throws LockAcquisitionException
     * @throws AuthenticationExpiredException     when there is no connection or Intuit rejects it
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    public function forceRefresh(): ApiKeyToken
    {
        return $this->refreshUnderLock(force: true);
    }

    /**
     * Exchanges a consent code and stores the resulting connection, under the same lock as every refresh.
     *
     * @throws LockAcquisitionException
     * @throws AuthenticationExpiredException     when Intuit rejects the code
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws KeyEncryptionFailedException
     */
    public function connectWithCode(#[SensitiveParameter] string $code): void
    {
        $this->lockManager->withLock(
            LockName::QuickBooksTokenRefresh->value,
            self::LOCK_TIMEOUT_SECONDS,
            function () use ($code): void {
                $grant = $this->oauthClient->exchangeCode($code);
                $this->connections->save($grant->connection);
                $this->accessTokens->put($grant->accessToken, $grant->accessTokenExpiresAt);
            },
        );
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    public function forgetAccessToken(): void
    {
        $this->accessTokens->forget();
    }

    /**
     * @throws LockAcquisitionException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    private function refreshUnderLock(bool $force): ApiKeyToken
    {
        return $this->lockManager->withLock(
            LockName::QuickBooksTokenRefresh->value,
            self::LOCK_TIMEOUT_SECONDS,
            function () use ($force): ApiKeyToken {
                // intentional: a contender that waited on the lock uses the token the holder just cached.
                $cached = $force ? null : $this->accessTokens->get();

                return $cached ?? $this->refreshStoredConnection();
            },
        );
    }

    /**
     * Must run under the token lock.
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    private function refreshStoredConnection(): ApiKeyToken
    {
        $grant = $this->oauthClient->refresh($this->storedConnection()->refreshToken);

        try {
            $this->accessTokens->put($grant->accessToken, $grant->accessTokenExpiresAt);
        } finally {
            // intentional: Intuit has already rotated the refresh token, so a cache failure must not skip the save.
            $this->saveRotated($grant);
        }

        return $grant->accessToken;
    }

    /**
     * @throws AuthenticationExpiredException when there is no connection
     * @throws ExternalServiceUnavailableException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     */
    private function storedConnection(): QuickBooksConnection
    {
        return $this->connections->find()
            ?? throw new AuthenticationExpiredException(self::SERVICE_NAME, 'No QuickBooks connection; run quickbooks:connect');
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws KeyEncryptionFailedException
     */
    private function saveRotated(QuickBooksTokenGrant $grant): void
    {
        try {
            $this->connections->save($grant->connection);
        } catch (DatabaseOperationFailedException|DuplicateRecordException|ExternalServiceUnavailableException|KeyEncryptionFailedException $e) {
            // intentional: Intuit has already rotated the refresh token, so losing this write costs a reconnect.
            $this->logger->critical('QuickBooks rotated refresh token was not stored; reconnect with quickbooks:connect', [
                'exception' => $e::class,
            ]);

            throw $e;
        }
    }
}
