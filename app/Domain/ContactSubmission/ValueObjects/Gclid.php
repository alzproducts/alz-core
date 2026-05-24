<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

use Webmozart\Assert\Assert;

final readonly class Gclid
{
    private function __construct(public string $value)
    {
        Assert::regex($value, '/^[A-Za-z0-9_-]{10,250}$/');
    }

    public static function from(string $value): self
    {
        return new self($value);
    }

    public static function fromNullableForm(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::from($raw);
    }
}
