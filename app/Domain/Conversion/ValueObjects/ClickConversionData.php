<?php

declare(strict_types=1);

namespace App\Domain\Conversion\ValueObjects;

use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

final readonly class ClickConversionData
{
    public function __construct(
        public string $gclid,
        public string $email,
        public DateTimeImmutable $convertedAt,
        public ?Money $value,
    ) {
        Assert::notEmpty($gclid, 'gclid cannot be empty');
        Assert::notEmpty($email, 'email cannot be empty');
    }
}
