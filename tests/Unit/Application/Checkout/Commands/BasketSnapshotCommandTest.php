<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Checkout\Commands;

use App\Application\Checkout\Commands\BasketSnapshotCommand;
use App\Application\Checkout\DTOs\VatReliefDeclarationDTO;
use App\Domain\Shared\Money\ValueObjects\Money;
use DateTimeImmutable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * BasketSnapshotCommand Unit Tests.
 *
 * Tests the pre-checkout basket snapshot command. IP address and user agent
 * are mandatory (captured server-side); all other fields are optional.
 * Money handles its own non-negative assertion — not tested here.
 */
#[CoversClass(BasketSnapshotCommand::class)]
final class BasketSnapshotCommandTest extends TestCase
{
    #[Test]
    public function it_creates_with_required_fields_only(): void
    {
        $total = Money::inclusive(129.99);

        $snapshot = new BasketSnapshotCommand(
            ipAddress: '203.0.113.50',
            userAgent: 'Mozilla/5.0',
            basketTotal: $total,
        );

        self::assertSame('203.0.113.50', $snapshot->ipAddress);
        self::assertSame('Mozilla/5.0', $snapshot->userAgent);
        self::assertSame($total, $snapshot->basketTotal);
        self::assertNull($snapshot->shippingMethodId);
        self::assertNull($snapshot->deliveryDate);
        self::assertNull($snapshot->giftNote);
        self::assertNull($snapshot->vatRelief);
    }

    #[Test]
    public function it_creates_with_all_fields(): void
    {
        $total = Money::inclusiveFromString('249.95');
        $deliveryDate = new DateTimeImmutable('2026-06-15');
        $vatRelief = new VatReliefDeclarationDTO(eligible: true, condition: 'arthritis');

        $snapshot = new BasketSnapshotCommand(
            ipAddress: '2001:db8::1',
            userAgent: 'Safari/605.1.15',
            basketTotal: $total,
            shippingMethodId: '40155',
            deliveryDate: $deliveryDate,
            giftNote: 'Happy birthday from the team',
            vatRelief: $vatRelief,
        );

        self::assertSame('2001:db8::1', $snapshot->ipAddress);
        self::assertSame('Safari/605.1.15', $snapshot->userAgent);
        self::assertSame($total, $snapshot->basketTotal);
        self::assertSame('40155', $snapshot->shippingMethodId);
        self::assertSame($deliveryDate, $snapshot->deliveryDate);
        self::assertSame('Happy birthday from the team', $snapshot->giftNote);
        self::assertSame($vatRelief, $snapshot->vatRelief);
    }

    #[Test]
    public function it_throws_for_empty_ip_address(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('IP address is required');

        new BasketSnapshotCommand(
            ipAddress: '',
            userAgent: 'Mozilla/5.0',
            basketTotal: Money::inclusive(10.00),
        );
    }

    #[Test]
    public function it_throws_for_empty_user_agent(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('User agent is required');

        new BasketSnapshotCommand(
            ipAddress: '203.0.113.50',
            userAgent: '',
            basketTotal: Money::inclusive(10.00),
        );
    }
}
