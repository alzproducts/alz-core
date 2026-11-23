<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use Webmozart\Assert\Assert;

/**
 * One fixed-window bucket: at most `limit` grants in each `decaySeconds` window.
 */
final readonly class QuickBooksRateLimitBucket
{
    /**
     * @param non-empty-string $name
     */
    public function __construct(
        public string $name,
        public int $decaySeconds,
        public int $limit,
        public bool $batchOnly,
    ) {
        Assert::greaterThan($decaySeconds, 0);
        Assert::greaterThan($limit, 0);
    }
}
