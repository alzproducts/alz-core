<?php

declare(strict_types=1);

namespace App\Application\Conversion;

use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;
use Webmozart\Assert\Assert;

final readonly class GoogleConversionUploadDTO
{
    public function __construct(
        public string $gclid,
        public ?string $email,
        public DateTimeImmutable $convertedAt,
        public ?Money $value,
        public ?string $phone = null,
    ) {
        Assert::notEmpty($gclid, 'gclid cannot be empty');
        Assert::nullOrNotEmpty($email, 'Email must be null or non-empty');
        Assert::true($email !== null || $phone !== null, 'At least one of email or phone must be provided');
    }
}
