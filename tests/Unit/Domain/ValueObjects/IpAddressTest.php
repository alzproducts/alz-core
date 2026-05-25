<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\ValueObjects;

use App\Domain\Exceptions\Data\InvalidFormatException;
use App\Domain\ValueObjects\IpAddress;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(IpAddress::class)]
final class IpAddressTest extends TestCase
{
    #[Test]
    #[DataProvider('validIpv4Addresses')]
    public function it_accepts_valid_ipv4(string $ip): void
    {
        $vo = IpAddress::from($ip);

        $this->assertSame($ip, $vo->value);
    }

    public static function validIpv4Addresses(): array
    {
        return [
            'loopback'         => ['127.0.0.1'],
            'documentation'    => ['203.0.113.42'],
            'zero'             => ['0.0.0.0'],
            'broadcast'        => ['255.255.255.255'],
        ];
    }

    #[Test]
    #[DataProvider('validIpv6Addresses')]
    public function it_accepts_valid_ipv6(string $ip): void
    {
        $vo = IpAddress::from($ip);

        $this->assertSame($ip, $vo->value);
    }

    public static function validIpv6Addresses(): array
    {
        return [
            'loopback'      => ['::1'],
            'documentation' => ['2001:db8::1'],
            'full'          => ['2001:0db8:85a3:0000:0000:8a2e:0370:7334'],
        ];
    }

    #[Test]
    #[DataProvider('invalidAddresses')]
    public function it_rejects_invalid_ip_addresses(string $input): void
    {
        $this->expectException(InvalidFormatException::class);

        IpAddress::from($input);
    }

    public static function invalidAddresses(): array
    {
        return [
            'empty string'        => [''],
            'whitespace'          => ['   '],
            'plain text'          => ['not-an-ip'],
            'out of range octets' => ['999.999.999.999'],
            'incomplete'          => ['192.168'],
            'trailing dot'        => ['192.168.1.'],
        ];
    }

    #[Test]
    public function it_exposes_field_and_value_on_the_exception(): void
    {
        try {
            IpAddress::from('invalid');
            $this->fail('Expected InvalidFormatException');
        } catch (InvalidFormatException $e) {
            $this->assertSame('ip_address', $e->field);
            $this->assertSame('invalid', $e->value);
        }
    }
}
