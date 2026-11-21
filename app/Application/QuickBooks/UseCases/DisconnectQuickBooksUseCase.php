<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\UseCases;

use App\Application\Contracts\LockManagerInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Application\Inventory\Enums\LockName;
use App\Application\QuickBooks\Results\QuickBooksDisconnectResult;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Revokes the grant at Intuit, then deletes the stored connection and the cached access token,
 * under the token lock so an in-flight refresh cannot write the connection back (plan D21).
 * `force` removes the connection even when the revoke fails or the row is unreadable (plan D20).
 */
final readonly class DisconnectQuickBooksUseCase
{
    public function __construct(
        private QuickBooksOAuthClientInterface $oauthClient,
        private QuickBooksConnectionRepositoryInterface $connections,
        private QuickBooksTokenService $tokens,
        private LockManagerInterface $lockManager,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws LockAcquisitionException
     * @throws AuthenticationExpiredException     when Intuit rejects the revoke and `force` is off
     * @throws ExternalServiceUnavailableException
     * @throws CorruptApiKeyException             when the stored row is unreadable and `force` is off
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    public function execute(bool $force, bool $dryRun): QuickBooksDisconnectResult
    {
        $this->logger->info('Disconnecting QuickBooks', ['force' => $force, 'dry_run' => $dryRun]);

        $result = $this->lockManager->withLock(
            LockName::QuickBooksTokenRefresh->value,
            QuickBooksTokenService::LOCK_TIMEOUT_SECONDS,
            fn(): QuickBooksDisconnectResult => $this->disconnectLocked($force, $dryRun),
        );

        $this->logger->info('Disconnected QuickBooks', ['result' => $result->value]);

        return $result;
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     */
    private function disconnectLocked(bool $force, bool $dryRun): QuickBooksDisconnectResult
    {
        try {
            $connection = $this->connections->find();
        } catch (CorruptApiKeyException $e) {
            return $this->removeUnreadable($e, $force, $dryRun);
        }

        if ($connection === null) {
            return QuickBooksDisconnectResult::NotConnected;
        }

        if ($dryRun) {
            return QuickBooksDisconnectResult::DryRun;
        }

        return $this->remove($this->revoke($connection->refreshToken, $force));
    }

    /**
     * @throws CorruptApiKeyException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function removeUnreadable(CorruptApiKeyException $e, bool $force, bool $dryRun): QuickBooksDisconnectResult
    {
        if (!$force) {
            throw $e;
        }

        $this->logger->warning('QuickBooks connection is unreadable; removing it without revoking');

        return $dryRun ? QuickBooksDisconnectResult::DryRun : $this->remove(QuickBooksDisconnectResult::RemovedWithoutRevoke);
    }

    /**
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     */
    private function revoke(#[SensitiveParameter] ApiKeyToken $refreshToken, bool $force): QuickBooksDisconnectResult
    {
        try {
            $this->oauthClient->revoke($refreshToken);
        } catch (AuthenticationExpiredException|ExternalServiceUnavailableException $e) {
            if (!$force) {
                throw $e;
            }

            $this->logger->warning('QuickBooks revoke failed; removing the connection anyway', ['exception' => $e::class]);

            return QuickBooksDisconnectResult::RemovedWithoutRevoke;
        }

        return QuickBooksDisconnectResult::Revoked;
    }

    /**
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     */
    private function remove(QuickBooksDisconnectResult $result): QuickBooksDisconnectResult
    {
        $this->connections->delete();
        $this->tokens->forgetAccessToken();

        return $result;
    }
}
