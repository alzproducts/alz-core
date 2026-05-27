<?php

declare(strict_types=1);

namespace App\Application\Conversion;

use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

final readonly class BingConversionUploadDTO
{
    public function __construct(
        public string $msclkid,
        public ?string $email,
        public DateTimeImmutable $convertedAt,
        public ?Money $value = null,
        public ?string $phone = null,
    ) {
        Assert::notEmpty($msclkid, 'msclkid cannot be empty');
        Assert::nullOrNotEmpty($email, 'Email must be null or non-empty');
        Assert::true($email !== null || $phone !== null, 'At least one of email or phone must be provided');
    }
}
