<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Exception;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheException;
use SensitiveParameter;
use Throwable;

/**
 * Holds the plaintext access token on the `quickbooks.cache_store` store, never the shared database cache (COR-280 D23).
 */
final readonly class CacheQuickBooksAccessTokenCache implements QuickBooksAccessTokenCacheInterface
{
    private const string SERVICE_NAME = 'QuickBooks token cache';

    private const int EXPIRY_MARGIN_SECONDS = 60;

    public function __construct(
        private Repository $store,
        private QuickBooksConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     */
    public function get(): ?ApiKeyToken
    {
        try {
            $value = $this->store->get($this->key());
        } catch (Exception|CacheException $e) {
            throw $this->unavailable($e);
        }

        return \is_string($value) && $value !== '' ? new ApiKeyToken($value) : null;
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    public function expiresAt(): ?DateTimeImmutable
    {
        try {
            $value = $this->store->get($this->expiryKey());
        } catch (Exception|CacheException $e) {
            throw $this->unavailable($e);
        }

        // intentional: the redis store returns an integer as a numeric string.
        return \is_numeric($value) ? CarbonImmutable::createFromTimestampUTC((int) $value) : null;
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    public function put(#[SensitiveParameter] ApiKeyToken $accessToken, DateTimeImmutable $expiresAt): void
    {
        $ttl = $expiresAt->getTimestamp() - CarbonImmutable::now()->getTimestamp() - self::EXPIRY_MARGIN_SECONDS;

        if ($ttl <= 0) {
            return;
        }

        try {
            // intentional: the expiry is dropped first and written last, so a failure between reads as unknown, never as another token's expiry.
            $this->store->forget($this->expiryKey());
            $this->store->put($this->key(), $accessToken->value, $ttl);
            $this->store->put($this->expiryKey(), $expiresAt->getTimestamp(), $ttl);
        } catch (Exception $e) {
            throw $this->unavailable($e);
        }
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    public function forget(): void
    {
        try {
            $this->store->forget($this->key());
            $this->store->forget($this->expiryKey());
        } catch (Exception $e) {
            throw $this->unavailable($e);
        }
    }

    private function key(): string
    {
        return 'quickbooks:access-token:' . $this->config->environment->value;
    }

    private function expiryKey(): string
    {
        return $this->key() . ':expires-at';
    }

    private function unavailable(Throwable $e): ExternalServiceUnavailableException
    {
        $this->logger->error('QuickBooks access-token cache unavailable', ['exception' => $e::class]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}
