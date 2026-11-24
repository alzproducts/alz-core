<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\Exceptions;

use App\Application\Exceptions\ApplicationException;
use Throwable;

final class OrderChunkUnfinishedException extends ApplicationException
{
    /**
     * @param non-empty-list<int> $references
     */
    public function __construct(
        public readonly array $references,
        ?Throwable $previous = null,
    ) {
        parent::__construct('A QuickBooks order chunk ran out of attempts', previous: $previous);
    }

    /**
     * @return array{references: non-empty-list<int>}
     */
    public function context(): array
    {
        return ['references' => $this->references];
    }
}
