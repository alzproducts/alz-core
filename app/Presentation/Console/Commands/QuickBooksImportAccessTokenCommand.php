<?php

declare(strict_types=1);

namespace App\Presentation\Console\Commands;

use App\Application\QuickBooks\Results\QuickBooksTokenLeaseResult;
use App\Application\QuickBooks\UseCases\ImportQuickBooksTokenLeaseUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\InvalidConfigurationException;
use App\Presentation\Console\Traits\RequiresLocalQuickBooksSandboxTrait;
use Carbon\CarbonImmutable;
use DateTimeInterface;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use RuntimeException;

/**
 * Stores the token that quickbooks:lease-access-token prints, so a verify stack can call the sandbox.
 */
final class QuickBooksImportAccessTokenCommand extends Command
{
    use RequiresLocalQuickBooksSandboxTrait;

    private const int MAX_INPUT_BYTES = 16_384;

    /** The token cache silently skips a token closer to expiry than its own margin. */
    private const int MINIMUM_LIFE_SECONDS = 120;

    protected $signature = 'quickbooks:import-access-token';

    protected $description = 'Store a leased QuickBooks sandbox access token read from stdin (local only)';

    /**
     * @throws RuntimeException When the validator cannot be resolved (container misconfiguration)
     */
    public function handle(): int
    {
        if (!self::isLocalQuickBooksSandbox()) {
            return $this->failOnStderr('Refused: importing is for the QuickBooks sandbox in a local environment', 'APP_ENV=local and QUICKBOOKS_ENVIRONMENT=sandbox');
        }

        $lease = self::parse(self::readInput());
        if ($lease === null) {
            return $this->failOnStderr('stdin holds no usable QuickBooks token lease', 'the output of quickbooks:lease-access-token is piped in');
        }

        return $this->store($lease);
    }

    private function store(QuickBooksTokenLeaseResult $lease): int
    {
        try {
            // intentional: resolved after the guard; the container throws on a production QuickBooks config.
            \app(ImportQuickBooksTokenLeaseUseCase::class)->execute($lease);
        } catch (InvalidConfigurationException $e) {
            return $this->failOnStderr('QuickBooks is not configured: ' . $e->getMessage(), 'the QUICKBOOKS_* keys in .env');
        } catch (ExternalServiceUnavailableException $e) {
            return $this->failOnStderr('Could not store the QuickBooks access token: ' . $e->getMessage(), 'the QuickBooks cache store (QUICKBOOKS_CACHE_STORE) is reachable');
        }

        $this->line($lease->expiresAt->format(DateTimeInterface::ATOM));

        return self::SUCCESS;
    }

    private static function readInput(): string
    {
        $input = \file_get_contents('php://stdin', length: self::MAX_INPUT_BYTES);

        return $input === false ? '' : $input;
    }

    /**
     * @throws RuntimeException When the validator cannot be resolved (container misconfiguration)
     */
    private static function parse(string $input): ?QuickBooksTokenLeaseResult
    {
        $data = \json_decode($input, true);
        if (!\is_array($data) || !self::hasLeaseShape($data)) {
            return null;
        }

        /** @var array{access_token: string, expires_at: string} $data */
        $expiresAt = new CarbonImmutable($data['expires_at']);

        return $expiresAt->getTimestamp() - CarbonImmutable::now()->getTimestamp() < self::MINIMUM_LIFE_SECONDS
            ? null
            : new QuickBooksTokenLeaseResult(new ApiKeyToken($data['access_token']), $expiresAt);
    }

    /**
     * @param array<mixed> $data
     *
     * @throws RuntimeException When the validator cannot be resolved (container misconfiguration)
     */
    private static function hasLeaseShape(array $data): bool
    {
        return Validator::make($data, [
            'access_token' => ['required', 'string', 'regex:/\A\S+\z/'],
            'expires_at' => ['required', 'string', 'date_format:' . DateTimeInterface::ATOM],
        ])->passes();
    }
}
