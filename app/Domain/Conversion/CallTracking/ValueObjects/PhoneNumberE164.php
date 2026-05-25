<?php

declare(strict_types=1);

namespace App\Domain\Conversion\CallTracking\ValueObjects;

use App\Domain\Exceptions\Data\InvalidFormatException;

final readonly class PhoneNumberE164
{
    private const string PATTERN = '/^\+[1-9]\d{6,14}$/';

    /** @throws InvalidFormatException */
    private function __construct(public string $value)
    {
        if (\preg_match(self::PATTERN, $value) !== 1) {
            throw new InvalidFormatException('phone_number_e164', $value);
        }
    }

    /** @throws InvalidFormatException */
    public static function from(string $value): self
    {
        return new self($value);
    }

    /** @throws InvalidFormatException */
    public static function fromNullableForm(?string $raw): ?self
    {
        if ($raw === null || $raw === '') {
            return null;
        }

        return self::from($raw);
    }
}
