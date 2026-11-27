<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\QuickBooks\OrderSync\DTOs\OrderRangeRequestDTO;
use App\Domain\Exceptions\ValidationFailedException;
use App\Domain\ValueObjects\DateRange;
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

    /**
     * intentional: refused here, as `DateRange` asserts the order and its assertion is a 500.
     *
     * @throws ValidationFailedException when `to` is before `from`
     */
    public function toRange(): OrderRangeRequestDTO
    {
        $from = CarbonImmutable::parse($this->from)->toDateTimeImmutable();
        $to = CarbonImmutable::parse($this->to)->toDateTimeImmutable();
        if ($to < $from) {
            $reason = 'The to date must not be before the from date.';

            throw new ValidationFailedException('The date range is outside the allowed limits.', ['to' => [$reason]]);
        }

        return new OrderRangeRequestDTO(new DateRange($from, $to), $this->force_retry);
    }
}
