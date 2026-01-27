<?php

declare(strict_types=1);

namespace App\Presentation\Concerns;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Collection;

/**
 * Provides chunked job dispatching for batch operations.
 *
 * Mix into controllers, commands, or other Presentation classes that need
 * to dispatch large batches of work as multiple queue jobs.
 *
 * @example
 * ```php
 * class MyController
 * {
 *     use DispatchesChunkedJobsTrait;
 *
 *     public function handle(): void
 *     {
 *         $jobCount = $this->dispatchInChunks($commands, MyJob::class);
 *     }
 * }
 * ```
 */
trait DispatchesChunkedJobsTrait
{
    /**
     * Default number of items per job batch.
     */
    protected int $defaultChunkSize = 25;

    /**
     * Dispatch items as chunked queue jobs.
     *
     * @template T
     *
     * @param list<T> $items Items to process
     * @param class-string<ShouldQueue> $jobClass Job class to dispatch (must accept list<T> as first constructor arg)
     * @param int|null $chunkSize Items per job (null uses $defaultChunkSize)
     *
     * @return int Number of jobs dispatched
     */
    protected function dispatchInChunks(array $items, string $jobClass, ?int $chunkSize = null): int
    {
        if ($items === []) {
            return 0;
        }

        $size = $chunkSize ?? $this->defaultChunkSize;

        /** @var Collection<int, Collection<int, T>> $chunks */
        $chunks = \collect($items)->chunk($size);

        $chunks->each(static function (Collection $chunk) use ($jobClass): void {
            /** @var list<T> $chunkList */
            $chunkList = $chunk->values()->all();
            // dispatch() comes from Dispatchable trait - can't be expressed in PHP type system
            /** @phpstan-ignore staticMethod.notFound */
            $jobClass::dispatch($chunkList);
        });

        return $chunks->count();
    }
}
