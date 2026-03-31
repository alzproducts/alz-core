<?php

declare(strict_types=1);

namespace App\Domain\Shared\Pagination\ValueObjects;

use Webmozart\Assert\Assert;

/**
 * Immutable value object representing a paginated request.
 *
 * Enforces: page >= 1, perPage >= 1, perPage <= MAX_PER_PAGE.
 */
final readonly class PageRequest
{
    private const int MAX_PER_PAGE = 1000;

    private function __construct(
        public int $page,
        public int $perPage,
    ) {
        Assert::positiveInteger($page);
        Assert::positiveInteger($perPage);
        Assert::lessThanEq($perPage, self::MAX_PER_PAGE);
    }

    public static function from(int $page, int $perPage): self
    {
        return new self($page, $perPage);
    }

    public static function firstPage(int $perPage = 500): self
    {
        return new self(1, $perPage);
    }
}
