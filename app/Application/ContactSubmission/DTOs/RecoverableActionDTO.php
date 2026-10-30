<?php

declare(strict_types=1);

namespace App\Application\ContactSubmission\DTOs;

use App\Application\Conversion\Enums\AdPlatform;
use App\Domain\ContactSubmission\Enums\ActionType;
use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;

final readonly class RecoverableActionDTO
{
    public function __construct(
        public string $actionId,
        public string $submissionId,
        public ActionType $actionType,
        public ?AdPlatform $adPlatform,
        public ?Money $quoteValue,
        public ?DateTimeImmutable $quoteConvertedAt,
    ) {}
}
