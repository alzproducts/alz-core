<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use Closure;
use Throwable;

/**
 * What happened inside a slot: the limiter's own throws, before and after the work, look like the work's.
 */
final class QuickBooksOrderChunkSlotAttempt
{
    public private(set) bool $ran = false;

    public private(set) ?Throwable $failure = null;

    /**
     * @param-immediately-invoked-callable $work
     *
     * @param Closure(): void $work
     *
     * @throws Throwable
     */
    public function run(Closure $work): void
    {
        $this->ran = true;

        try {
            $work();
        } catch (Throwable $e) {
            $this->failure = $e;

            throw $e;
        }
    }
}
