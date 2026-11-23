<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\Results;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerMatchDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerResolutionDTO;
use App\Application\QuickBooks\OrderSync\Enums\CustomerFailureKind;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomerResolutionResult::class)]
final class CustomerResolutionResultTest extends TestCase
{
    #[Test]
    public function it_finds_a_resolution_ignoring_case(): void
    {
        $matched = CustomerResolutionDTO::matched('Jo Bloggs', new CustomerMatchDTO('5', 'Jo Bloggs', null, null));

        $result = CustomerResolutionResult::of([$matched]);

        $this->assertSame($matched, $result->forDisplayName('JO BLOGGS'));
        $this->assertNull($result->forDisplayName('Someone Else'));
    }

    #[Test]
    public function the_first_resolution_per_folded_name_wins(): void
    {
        $first = CustomerResolutionDTO::failed('Jo Bloggs', CustomerFailureKind::Inactive);

        $result = CustomerResolutionResult::of([$first, CustomerResolutionDTO::matched('jo bloggs', new CustomerMatchDTO('5', 'jo bloggs', null, null))]);

        $this->assertSame($first, $result->forDisplayName('Jo Bloggs'));
    }

    #[Test]
    public function it_finds_a_resolution_ignoring_edge_spaces(): void
    {
        $matched = CustomerResolutionDTO::matched(' Jo Bloggs', new CustomerMatchDTO('5', 'Jo Bloggs', null, null));

        $this->assertSame($matched, CustomerResolutionResult::of([$matched])->forDisplayName('Jo Bloggs '));
    }

    #[Test]
    public function an_all_digit_display_name_is_found(): void
    {
        $matched = CustomerResolutionDTO::matched('12345', new CustomerMatchDTO('8', '12345', null, null));

        $this->assertSame($matched, CustomerResolutionResult::of([$matched])->forDisplayName('12345'));
    }
}
