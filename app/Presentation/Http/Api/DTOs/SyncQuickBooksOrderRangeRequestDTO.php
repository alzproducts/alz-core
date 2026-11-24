<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRequestDTO;
use Carbon\CarbonImmutable;
use Spatie\LaravelData\Attributes\Validation\BooleanType;
use Spatie\LaravelData\Attributes\Validation\DateFormat;
use Spatie\LaravelData\Data;

final class SyncQuickBooksOrderRangeRequestDTO extends Data
{
    public function __construct(
        #[DateFormat('Y-m-d')]
        public readonly string $from,
        #[DateFormat('Y-m-d')]
        public readonly string $to,
        #[BooleanType]
        public readonly bool $force_retry = false,
    ) {}

    public function toRange(): OrderRangeRequestDTO
    {
        return new OrderRangeRequestDTO(
            CarbonImmutable::parse($this->from)->toDateTimeImmutable(),
            CarbonImmutable::parse($this->to)->toDateTimeImmutable(),
            $this->force_retry,
        );
    }
}
