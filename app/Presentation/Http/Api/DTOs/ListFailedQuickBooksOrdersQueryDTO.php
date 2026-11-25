<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\FailedListStatus;
use Spatie\LaravelData\Attributes\Validation\IntegerType;
use Spatie\LaravelData\Attributes\Validation\Max;
use Spatie\LaravelData\Attributes\Validation\Min;
use Spatie\LaravelData\Data;

final class ListFailedQuickBooksOrdersQueryDTO extends Data
{
    public function __construct(
        public readonly FailedListStatus $status = FailedListStatus::Failed,
        #[IntegerType, Min(1), Max(1000)]
        public readonly int $per_page = 50,
        #[IntegerType, Min(1)]
        public readonly int $page = 1,
    ) {}
}
