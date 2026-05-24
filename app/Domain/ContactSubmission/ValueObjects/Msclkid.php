<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

use Webmozart\Assert\Assert;

final readonly class Msclkid
{
    private function __construct(public string $value)
    {
        Assert::regex($value, '/^[0-9a-f]{32}-[01]$/');
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
