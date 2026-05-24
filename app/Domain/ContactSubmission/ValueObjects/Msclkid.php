<?php

declare(strict_types=1);

namespace App\Domain\ContactSubmission\ValueObjects;

use App\Domain\Exceptions\Data\InvalidFormatException;

final readonly class Msclkid
{
    private const string PATTERN = '/^[0-9a-f]{32}(-[01])?$/';

    /**
     * @throws InvalidFormatException
     */
    private function __construct(public string $value)
    {
        if (\preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidFormatException('msclkid', $value);
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
