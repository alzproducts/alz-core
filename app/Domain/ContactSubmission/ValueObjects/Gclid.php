<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

use App\Domain\Exceptions\Data\InvalidFormatException;

final readonly class Gclid
{
    private const string PATTERN = '/^[A-Za-z0-9_-]{10,250}$/';

    /**
     * @throws InvalidFormatException
     */
    private function __construct(public string $value)
    {
        if (\preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidFormatException('gclid', $value);
        }
    }

    /**
     * @throws InvalidFormatException
     */
    public static function from(string $value): self
    {
        return new self($value);
    }

    /**
     * @throws InvalidFormatException
     */
    public static function fromNullableForm(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::from($raw);
    }
}
