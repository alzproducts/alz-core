<?php

declare(strict_types=1);

namespace App\Domain\QuickBooks\Exceptions;

use App\Domain\Exceptions\DomainException;
use Override;

/**
 * A QuickBooks call could not start in time, so nothing was sent.
 * `secondsLeft` is the time that remained before the latest moment the call could start; negative once past it.
 */
final class QuickBooksDeadlineExceededException extends DomainException
{
    public function __construct(
        public readonly float $secondsLeft,
    ) {
        parent::__construct('QuickBooks call could not start before its deadline');
    }

    #[Override]
    public function context(): array
    {
        return ['seconds_left' => $this->secondsLeft];
    }
}
