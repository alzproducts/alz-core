<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\Services;

use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Application\Inventory\Enums\LockName;
use App\Application\QuickBooks\Results\QuickBooksTokenLeaseResult;
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

    /** Longer than the interval at which a verify stack re-leases, so a leased token outlives it. */
    public const int LEASE_FLOOR_SECONDS = 900;

    public function __construct(
        private QuickBooksOAuthClientInterface $oauthClient,
        private QuickBooksConnectionRepositoryInterface $connections,
        private QuickBooksAccessTokenCacheInterface $accessTokens,
        private LockManagerInterface $lockManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * The cached access token, refreshed under the lock when none is cached.
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
     * An access token with at least LEASE_FLOOR_SECONDS left, refreshed only when the cached one has less
     * or an unknown expiry. Null when there is no stored connection, even with a token cached.
     *
     * @throws LockAcquisitionException
     * @throws AuthenticationExpiredException     when Intuit rejects the connection
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws CorruptApiKeyException
     * @throws KeyEncryptionFailedException
     */
    public function lease(): ?QuickBooksTokenLeaseResult
    {
        return $this->lockManager->withLock(
            LockName::QuickBooksTokenRefresh->value,
            self::LOCK_TIMEOUT_SECONDS,
            function (): ?QuickBooksTokenLeaseResult {
                $connection = $this->connections->find();

                return $connection === null ? null : $this->cachedLease() ?? $this->refreshedLease($connection);
            },
        );
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
        $connection = $this->connections->find();
        if ($connection === null) {
            throw new AuthenticationExpiredException(self::SERVICE_NAME, 'No QuickBooks connection; run quickbooks:connect');
        }

        return $this->refresh($connection)->accessToken;
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    private function cachedLease(): ?QuickBooksTokenLeaseResult
    {
        $accessToken = $this->accessTokens->get();
        $expiresAt = $this->accessTokens->expiresAt();

        if ($accessToken === null || $expiresAt === null) {
            return null;
        }

        return $expiresAt->getTimestamp() - \time() >= self::LEASE_FLOOR_SECONDS
            ? new QuickBooksTokenLeaseResult($accessToken, $expiresAt)
            : null;
    }

    /**
     * Must run under the token lock.
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws KeyEncryptionFailedException
     */
    private function refreshedLease(QuickBooksConnection $connection): QuickBooksTokenLeaseResult
    {
        $grant = $this->refresh($connection);

        return new QuickBooksTokenLeaseResult($grant->accessToken, $grant->accessTokenExpiresAt);
    }

    /**
     * Must run under the token lock.
     *
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws KeyEncryptionFailedException
     */
    private function refresh(QuickBooksConnection $connection): QuickBooksTokenGrant
    {
        $grant = $this->oauthClient->refresh($connection->refreshToken);

        try {
            $this->accessTokens->put($grant->accessToken, $grant->accessTokenExpiresAt);
        } finally {
            // intentional: Intuit has already rotated the refresh token, so a cache failure must not skip the save.
            $this->saveRotated($grant);
        }

        return $grant;
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
