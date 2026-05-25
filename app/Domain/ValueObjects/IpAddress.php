<?php

declare(strict_types=1);

namespace App\Domain\ValueObjects;

use App\Domain\Exceptions\Data\InvalidFormatException;

final readonly class IpAddress
{
    /** @throws InvalidFormatException */
    private function __construct(
        public string $value,
    ) {
        if (\filter_var($value, \FILTER_VALIDATE_IP) === false) {
            throw new InvalidFormatException('ip_address', $value);
        }
    }

    /** @throws InvalidFormatException */
    public static function from(string $value): self
    {
        return new self($value);
    }
}
