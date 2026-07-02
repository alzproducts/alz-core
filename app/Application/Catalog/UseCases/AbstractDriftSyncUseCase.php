<?php

declare(strict_types=1);

namespace App\Application\Catalog\UseCases;

use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Data\InvalidEnumValueException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\DuplicateRecordException;
use Psr\Log\LoggerInterface;

/**
 * Template method for drift-sync use cases.
 *
 * Centralises the log → drift query → early-return → per-item dispatch → count
 * log pipeline shared by all 8 catalog drift syncs (3 label + 5 filter).
 *
 * Children keep their strongly-typed `execute()` as the public API and
 * delegate to `process()`.
 *
 * @template T of object
 */
abstract readonly class AbstractDriftSyncUseCase
{
    public function __construct(
        private LoggerInterface $logger,
    ) {}

    /**
     * Template method: log → fetch drift → early-return → dispatch loop → count log.
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    protected function process(): void
    {
        $name = $this->syncName();

        $this->logger->info("{$name}: checking for drift");

        $drift = $this->fetchDrift();

        if ($drift === []) {
            $this->logger->info("{$name}: no drift detected");

            return;
        }

        $counts = $this->dispatchAllAndCount($drift);

        $this->logger->info("{$name}: dispatched drift corrections", $counts);
    }

    /**
     * @param list<T> $drift
     * @return array<string, int>
     */
    private function dispatchAllAndCount(array $drift): array
    {
        $total = 0;
        $breakdown = [];

        foreach ($drift as $item) {
            $this->dispatchOne($item);
            ++$total;

            $key = $this->countKey($item);

            if ($key !== null) {
                $breakdown[$key] = ($breakdown[$key] ?? 0) + 1;
            }
        }

        return ['count' => $total] + $breakdown;
    }

    /**
     * @return list<T>
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    abstract protected function fetchDrift(): array;

    /**
     * @param T $item
     *
     * @throws DatabaseOperationFailedException
     * @throws DuplicateRecordException
     * @throws ExternalServiceUnavailableException
     * @throws InvalidEnumValueException
     */
    abstract protected function dispatchOne(object $item): void;

    abstract protected function syncName(): string;

    /**
     * Return a breakdown key for this item, or null for total-only counting.
     *
     * @param T $item
     */
    protected function countKey(object $item): ?string
    {
        return null;
    }
}
