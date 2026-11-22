<?php

declare(strict_types=1);

namespace App\Infrastructure\QuickBooks;

use Webmozart\Assert\Assert;

/**
 * One sliding-log bucket: at most `limit` grants in any `windowMs` span.
 */
final readonly class QuickBooksRateLimitBucket
{
    /**
     * @param non-empty-string $name
     */
    public function __construct(
        public string $name,
        public int $windowMs,
        public int $limit,
        public bool $batchOnly,
    ) {
        Assert::greaterThan($windowMs, 0);
        Assert::greaterThan($limit, 0);
    }
}
