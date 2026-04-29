<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\Responses;

use App\Domain\Catalog\Order\ValueObjects\OrderShipping;
use App\Infrastructure\Shopwired\Responses\OrderShippingResponse;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(OrderShippingResponse::class)]
final class OrderShippingResponseTest extends TestCase
{
    // ========================================================================
    // from() — name nullability
    // ========================================================================

    #[Test]
    public function it_accepts_a_null_name(): void
    {
        $response = OrderShippingResponse::from(self::payload(['name' => null]));

        self::assertNull($response->name);
    }

    #[Test]
    public function it_accepts_an_empty_string_name(): void
    {
        $response = OrderShippingResponse::from(self::payload(['name' => '']));

        self::assertSame('', $response->name);
    }

    #[Test]
    public function it_accepts_a_normal_string_name(): void
    {
        $response = OrderShippingResponse::from(self::payload(['name' => 'Royal Mail Tracked 24']));

        self::assertSame('Royal Mail Tracked 24', $response->name);
    }

    // ========================================================================
    // toDomain() — empty-string coercion
    // ========================================================================

    #[Test]
    public function it_coerces_empty_string_name_to_null_on_domain_conversion(): void
    {
        $response = OrderShippingResponse::from(self::payload(['name' => '']));

        $shipping = $response->toDomain();

        self::assertNull($shipping->name);
    }

    #[Test]
    public function it_passes_null_name_through_unchanged_on_domain_conversion(): void
    {
        $response = OrderShippingResponse::from(self::payload(['name' => null]));

        $shipping = $response->toDomain();

        self::assertNull($shipping->name);
    }

    #[Test]
    public function it_passes_a_normal_string_name_through_unchanged_on_domain_conversion(): void
    {
        $response = OrderShippingResponse::from(self::payload(['name' => 'Royal Mail Tracked 24']));

        $shipping = $response->toDomain();

        self::assertSame('Royal Mail Tracked 24', $shipping->name);
    }

    #[Test]
    public function it_maps_value_to_charge_net_and_preserves_id_and_vat_rate(): void
    {
        $response = OrderShippingResponse::from(self::payload([
            'id' => 42,
            'name' => 'Royal Mail Tracked 24',
            'value' => 4.99,
            'vat_rate' => 20.0,
        ]));

        $shipping = $response->toDomain();

        self::assertInstanceOf(OrderShipping::class, $shipping);
        self::assertSame(42, $shipping->id);
        self::assertSame('Royal Mail Tracked 24', $shipping->name);
        self::assertSame(4.99, $shipping->chargeNet);
        self::assertSame(20.0, $shipping->vatRate);
    }

    // ========================================================================
    // Fixtures
    // ========================================================================

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private static function payload(array $overrides = []): array
    {
        return [
            'id' => 1,
            'name' => 'Royal Mail Tracked 24',
            'value' => 4.99,
            'vat_rate' => 20.0,
            ...$overrides,
        ];
    }
}
