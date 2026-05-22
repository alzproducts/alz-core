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
        public string $email,
        public DateTimeImmutable $convertedAt,
        public ?Money $value = null,
        public ?string $phone = null,
    ) {
        Assert::notEmpty($msclkid, 'msclkid cannot be empty');
        Assert::notEmpty($email, 'email cannot be empty');
    }
}
