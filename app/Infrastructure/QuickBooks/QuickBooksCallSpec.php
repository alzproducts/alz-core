<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use App\Domain\Shared\ValueObjects\Deadline;

final readonly class QuickBooksCallSpec
{
    public function __construct(
        public QuickBooksCall $call,
        public Deadline $deadline,
    ) {}
}
