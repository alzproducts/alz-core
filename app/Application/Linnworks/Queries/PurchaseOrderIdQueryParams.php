<?php

declare(strict_types=1);

namespace App\Application\Linnworks\Queries;

use App\Application\Linnworks\Enums\PurchaseOrderIdScope;
use DateTimeImmutable;

final readonly class PurchaseOrderIdQueryParams
{
    private function __construct(
        public PurchaseOrderIdScope $scope,
        public ?DateTimeImmutable $createdSince = null,
        public bool $includeDeliveredToday = true,
        public ?DateTimeImmutable $from = null,
        public ?DateTimeImmutable $to = null,
    ) {}

    public static function fastSync(DateTimeImmutable $since, bool $includeDeliveredToday = true): self
    {
        return new self(
            scope: PurchaseOrderIdScope::FastSync,
            createdSince: $since,
            includeDeliveredToday: $includeDeliveredToday,
        );
    }

    public static function byDateRange(DateTimeImmutable $from, DateTimeImmutable $to): self
    {
        return new self(
            scope: PurchaseOrderIdScope::DateRange,
            from: $from,
            to: $to,
        );
    }

    public static function all(): self
    {
        return new self(scope: PurchaseOrderIdScope::All);
    }
}
