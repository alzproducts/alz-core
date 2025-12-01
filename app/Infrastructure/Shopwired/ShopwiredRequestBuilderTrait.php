<?php

declare(strict_types=1);

namespace App\Infrastructure\Shopwired;

/**
 * Shared request building utilities for ShopWired API clients.
 *
 * Provides consistent request formatting for batched/pooled operations.
 * All methods are static for simplicity - no state management needed.
 *
 * @internal For use within ShopWired infrastructure only
 */
trait ShopwiredRequestBuilderTrait
{
    /**
     * Build pool request definitions from batched data.
     *
     * Transforms batched data into the structure expected by poolPost():
     * `array<string, array{endpoint: string, data: array}>`
     *
     * Keys are generated as "batch_0", "batch_1", etc. for error tracking.
     *
     * @template T
     *
     * @param list<list<T>> $batches Chunked items to send
     * @param string $endpoint API endpoint for all batches
     * @param callable(list<T>): array<mixed> $formatter Transforms batch items to API payload
     *
     * @return array<string, array{endpoint: string, data: array<mixed>}>
     */
    private static function buildPoolRequests(array $batches, string $endpoint, callable $formatter): array
    {
        $requests = [];

        foreach ($batches as $index => $batch) {
            $requests["batch_{$index}"] = [
                'endpoint' => $endpoint,
                'data' => $formatter($batch),
            ];
        }

        return $requests;
    }
}
