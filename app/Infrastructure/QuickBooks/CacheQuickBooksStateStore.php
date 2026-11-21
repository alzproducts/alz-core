<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksStateStoreInterface;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use Exception;
use Illuminate\Contracts\Cache\Repository;
use Psr\Log\LoggerInterface;
use Psr\SimpleCache\CacheException;
use Random\RandomException;
use Throwable;

final readonly class CacheQuickBooksStateStore implements QuickBooksStateStoreInterface
{
    private const string SERVICE_NAME = 'QuickBooks state store';

    private const int TTL_SECONDS = 600;

    public function __construct(
        private Repository $store,
        private QuickBooksConfig $config,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws ExternalServiceUnavailableException
     */
    public function issue(): string
    {
        try {
            $state = \bin2hex(\random_bytes(32));
            $this->store->put($this->key($state), true, self::TTL_SECONDS);
        } catch (Exception|RandomException $e) {
            throw $this->unavailable($e);
        }

        return $state;
    }

    /**
     * @throws ExternalServiceUnavailableException
     */
    public function consume(string $state): bool
    {
        $key = $this->key($state);

        try {
            if ($this->store->get($key) === null) {
                return false;
            }

            // intentional: `add` is the atomic claim; `pull` is get-then-forget and lets a replay pass twice (P3).
            if (!$this->store->add($key . ':consumed', true, self::TTL_SECONDS)) {
                return false;
            }

            $this->store->forget($key);
        } catch (Exception|CacheException $e) {
            throw $this->unavailable($e);
        }

        return true;
    }

    private function key(string $state): string
    {
        return 'quickbooks:oauth-state:' . $this->config->environment->value . ':' . $state;
    }

    private function unavailable(Throwable $e): ExternalServiceUnavailableException
    {
        $this->logger->error('QuickBooks OAuth state store unavailable', ['exception' => $e::class]);

        return new ExternalServiceUnavailableException(self::SERVICE_NAME, previous: $e);
    }
}
