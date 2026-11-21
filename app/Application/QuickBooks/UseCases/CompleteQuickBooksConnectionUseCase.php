<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\UseCases;

use App\Application\Contracts\QuickBooks\QuickBooksOAuthClientInterface;
use App\Application\Contracts\QuickBooks\QuickBooksStateStoreInterface;
use App\Application\QuickBooks\Results\QuickBooksCallbackResult;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use Psr\Log\LoggerInterface;
use SensitiveParameter;

/**
 * Handles Intuit's consent redirect. Every rejection happens before the token endpoint is called.
 */
final readonly class CompleteQuickBooksConnectionUseCase
{
    private const string STATE_PATTERN = '/^[a-f0-9]{64}$/D';

    public function __construct(
        private QuickBooksStateStoreInterface $states,
        private QuickBooksOAuthClientInterface $oauthClient,
        private QuickBooksTokenService $tokens,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws LockAcquisitionException
     * @throws AuthenticationExpiredException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidApiResponseException
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws KeyEncryptionFailedException
     */
    public function execute(?string $state, #[SensitiveParameter] ?string $code, ?string $realmId): QuickBooksCallbackResult
    {
        $this->logger->info('QuickBooks connection callback received');

        $rejection = $this->rejection($state, $code, $realmId);
        if ($rejection !== null || $code === null) {
            $rejection ??= QuickBooksCallbackResult::MissingCode;
            $this->logger->warning('QuickBooks connection callback rejected', ['outcome' => $rejection->value]);

            return $rejection;
        }

        $this->tokens->connectWithCode($code);

        $this->logger->info('QuickBooks connection stored');

        return QuickBooksCallbackResult::Connected;
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    private function rejection(?string $state, #[SensitiveParameter] ?string $code, ?string $realmId): ?QuickBooksCallbackResult
    {
        // intentional: the pattern check runs before the store sees attacker-controlled input.
        if ($state === null || \preg_match(self::STATE_PATTERN, $state) !== 1 || !$this->states->consume($state)) {
            return QuickBooksCallbackResult::InvalidState;
        }

        if ($code === null || $code === '') {
            return QuickBooksCallbackResult::MissingCode;
        }

        return $realmId === $this->oauthClient->expectedRealmId() ? null : QuickBooksCallbackResult::RealmMismatch;
    }
}
