<?php

declare(strict_types=1);

namespace App\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\Enums\CustomerFailureKind;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use Webmozart\Assert\Assert;

/**
 * One draft display name's outcome: a match, or why no document can post against it.
 */
final readonly class CustomerResolutionDTO
{
    private function __construct(
        public string $displayName,
        public ?CustomerMatchDTO $match,
        public ?CustomerFailureKind $failure,
        public ?QuickBooksFault $fault,
    ) {}

    public static function matched(string $displayName, CustomerMatchDTO $match): self
    {
        return new self($displayName, $match, null, null);
    }

    public static function failed(string $displayName, CustomerFailureKind $failure, ?QuickBooksFault $fault = null): self
    {
        Assert::false($failure === CustomerFailureKind::Faulted && $fault === null, 'A Faulted resolution carries its Fault');

        return new self($displayName, null, $failure, $fault);
    }
}
