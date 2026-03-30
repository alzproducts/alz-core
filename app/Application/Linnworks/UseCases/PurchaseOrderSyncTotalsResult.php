<?php

declare(strict_types=1);

namespace App\Application\Linnworks\UseCases;

use App\Application\Results\SyncResult;

/**
 * Mutable accumulator for purchase order sync progress tracking.
 *
 * Tracks fetched/saved/failed counts during the buffer/flush loop
 * and converts to an immutable SyncResult when complete.
 *
 * @internal Used only by SyncPurchaseOrderCoreUseCase and SyncPurchaseOrderFullUseCase
 */
final class PurchaseOrderSyncTotalsResult
{
    /** @var int<0, max> */
    private int $fetched = 0;

    /** @var int<0, max> */
    private int $saved = 0;

    /** @var list<string> */
    private array $failedReferences = [];

    public function addFetched(): void
    {
        $this->fetched++;
    }

    public function addSaved(): void
    {
        $this->saved++;
    }

    public function addFailed(string $ref): void
    {
        $this->failedReferences[] = $ref;
    }

    /**
     * Convert to an immutable SyncResult.
     */
    public function toSyncResult(): SyncResult
    {
        return new SyncResult(
            fetched: $this->fetched,
            saved: $this->saved,
            failed: \count($this->failedReferences),
            failedReferences: $this->failedReferences,
        );
    }

    /**
     * Get log-friendly context array.
     *
     * @return array{fetched: int<0, max>, saved: int<0, max>, failed: int<0, max>}
     */
    public function toLogContext(): array
    {
        return [
            'fetched' => $this->fetched,
            'saved' => $this->saved,
            'failed' => \count($this->failedReferences),
        ];
    }
}
