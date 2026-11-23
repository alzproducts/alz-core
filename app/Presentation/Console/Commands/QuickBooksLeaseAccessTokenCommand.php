<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\QuickBooks\Results\QuickBooksTokenLeaseResult;
use App\Application\QuickBooks\UseCases\LeaseQuickBooksAccessTokenUseCase;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\CorruptApiKeyException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\InvalidApiResponseException;
use App\Domain\Exceptions\Api\KeyEncryptionFailedException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Presentation\Console\Traits\RequiresLocalQuickBooksSandboxTrait;
use DateTimeInterface;
use Illuminate\Console\Command;
use JsonException;

/**
 * Prints a sandbox access token for a verify stack to import. Run it only into a pipe: its stdout is a credential.
 */
final class QuickBooksLeaseAccessTokenCommand extends Command
{
    use RequiresLocalQuickBooksSandboxTrait;

    protected $signature = 'quickbooks:lease-access-token';

    protected $description = 'Print a QuickBooks sandbox access token and its expiry as JSON, for quickbooks:import-access-token (local only)';

    public function handle(): int
    {
        if (!self::isLocalQuickBooksSandbox()) {
            return $this->failOnStderr('Refused: leasing is for the QuickBooks sandbox in a local environment', 'APP_ENV=local and QUICKBOOKS_ENVIRONMENT=sandbox');
        }

        return $this->lease();
    }

    private function lease(): int
    {
        try {
            // intentional: resolved after the guard; the container throws on a production QuickBooks config.
            $lease = \app(LeaseQuickBooksAccessTokenUseCase::class)->execute();
        } catch (InvalidConfigurationException $e) {
            return $this->failOnStderr('QuickBooks is not configured: ' . $e->getMessage(), 'the QUICKBOOKS_* keys in .env');
        } catch (AuthenticationExpiredException|CorruptApiKeyException|KeyEncryptionFailedException $e) {
            return $this->failOnStderr('The stored QuickBooks connection cannot be used: ' . $e->getMessage(), 'reconnect with quickbooks:connect');
        } catch (ExternalServiceUnavailableException|LockAcquisitionException|InvalidApiResponseException|DatabaseOperationFailedException|DuplicateRecordException $e) {
            return $this->failOnStderr('Could not lease a QuickBooks access token: ' . $e->getMessage(), 'the local database, the QuickBooks cache store and Intuit are reachable');
        }

        return $lease === null ? self::FAILURE : $this->printLease($lease);
    }

    private function printLease(QuickBooksTokenLeaseResult $lease): int
    {
        try {
            $this->line(\json_encode([
                'access_token' => $lease->accessToken->value,
                'expires_at' => $lease->expiresAt->format(DateTimeInterface::ATOM),
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_SLASHES));
        } catch (JsonException) {
            return $this->failOnStderr('The QuickBooks access token could not be encoded', 'Intuit returned a valid token');
        }

        return self::SUCCESS;
    }
}
