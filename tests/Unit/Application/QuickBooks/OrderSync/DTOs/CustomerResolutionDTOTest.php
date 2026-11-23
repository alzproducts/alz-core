<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\OrderSync\DTOs;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerResolutionDTO;
use App\Application\QuickBooks\OrderSync\Enums\CustomerFailureKind;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomerResolutionDTO::class)]
final class CustomerResolutionDTOTest extends TestCase
{
    #[Test]
    public function a_faulted_resolution_needs_its_fault(): void
    {
        $this->expectException(InvalidArgumentException::class);

        CustomerResolutionDTO::failed('Jo Bloggs', CustomerFailureKind::Faulted);
    }

    #[Test]
    public function a_faulted_resolution_keeps_its_fault(): void
    {
        $fault = new QuickBooksFault(QuickBooksFaultKind::Validation, '6000', 'Name', 'Rejected');

        $resolution = CustomerResolutionDTO::failed('Jo Bloggs', CustomerFailureKind::Faulted, $fault);

        $this->assertSame($fault, $resolution->fault);
        $this->assertSame(CustomerFailureKind::Faulted, $resolution->failure);
        $this->assertNull($resolution->match);
    }

    #[Test]
    public function an_inactive_resolution_carries_no_fault(): void
    {
        $this->assertNull(CustomerResolutionDTO::failed('Jo Bloggs', CustomerFailureKind::Inactive)->fault);
    }
}
