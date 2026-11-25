<?php

declare(strict_types=1);

namespace Tests\Fixtures\QuickBooks;

use Closure;
use Illuminate\Contracts\Redis\Factory;
use Illuminate\Redis\Connections\Connection;
use Override;
use RuntimeException;
use Throwable;

/**
 * Answers the concurrency limiter's two scripts; a slot is free unless told otherwise.
 */
final class FakeSlotRedis extends Connection implements Factory
{
    public bool $free = true;

    public bool $held = false;

    public ?Throwable $takeFailure = null;

    public ?Throwable $releaseFailure = null;

    /** @var list<string|null> */
    public array $connections = [];

    #[Override]
    public function connection($name = null): Connection
    {
        $this->connections[] = $name;

        return $this;
    }

    /**
     * @param array<array-key, mixed> $parameters
     */
    #[Override]
    public function command($method, array $parameters = []): ?string
    {
        $script = $parameters[0] ?? null;
        if ($method !== 'eval' || !\is_string($script)) {
            throw new RuntimeException('Only the limiter scripts are answered');
        }

        return \str_contains($script, 'mget') ? $this->take() : $this->release();
    }

    /**
     * @param array<array-key, mixed>|string $channels
     */
    #[Override]
    public function createSubscription($channels, Closure $callback, $method = 'subscribe'): void {}

    private function take(): ?string
    {
        if ($this->takeFailure !== null) {
            throw $this->takeFailure;
        }
        if (!$this->free) {
            return null;
        }
        $this->held = true;

        return 'slot1';
    }

    private function release(): null
    {
        if ($this->releaseFailure !== null) {
            throw $this->releaseFailure;
        }
        $this->held = false;

        return null;
    }
}
