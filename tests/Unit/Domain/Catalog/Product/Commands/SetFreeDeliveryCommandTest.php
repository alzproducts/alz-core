<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\Product\Commands;

use App\Domain\Catalog\Product\Commands\SetFreeDeliveryCommand;
use App\Domain\Catalog\Product\Enums\FreeDeliveryType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ValueError;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(SetFreeDeliveryCommand::class)]
final class SetFreeDeliveryCommandTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_constructs_with_sku_identifier(): void
    {
        $command = new SetFreeDeliveryCommand('SKU-123', FreeDeliveryType::Standard);

        self::assertSame('SKU-123', $command->identifier);
        self::assertSame(FreeDeliveryType::Standard, $command->freeDeliveryType);
    }

    #[Test]
    public function it_constructs_with_product_id_identifier(): void
    {
        $command = new SetFreeDeliveryCommand(12345, FreeDeliveryType::Express);

        self::assertSame(12345, $command->identifier);
        self::assertSame(FreeDeliveryType::Express, $command->freeDeliveryType);
    }

    #[Test]
    public function it_rejects_empty_sku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SKU identifier cannot be empty');

        new SetFreeDeliveryCommand('', FreeDeliveryType::Standard);
    }

    #[Test]
    public function it_rejects_whitespace_only_sku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('SKU identifier cannot be empty');

        new SetFreeDeliveryCommand('   ', FreeDeliveryType::Standard);
    }

    #[Test]
    public function it_rejects_zero_product_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product ID must be positive');

        new SetFreeDeliveryCommand(0, FreeDeliveryType::Standard);
    }

    #[Test]
    public function it_rejects_negative_product_id(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Product ID must be positive');

        new SetFreeDeliveryCommand(-1, FreeDeliveryType::Standard);
    }

    /*
    |--------------------------------------------------------------------------
    | fromInput() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function from_input_creates_command_with_valid_type(): void
    {
        $command = SetFreeDeliveryCommand::fromInput('SKU-456', 'Express');

        self::assertSame('SKU-456', $command->identifier);
        self::assertSame(FreeDeliveryType::Express, $command->freeDeliveryType);
    }

    #[Test]
    public function from_input_throws_for_invalid_type(): void
    {
        $this->expectException(ValueError::class);

        SetFreeDeliveryCommand::fromInput('SKU-456', 'invalid');
    }

    /*
    |--------------------------------------------------------------------------
    | isSkuIdentifier() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function is_sku_identifier_returns_true_for_string(): void
    {
        $command = new SetFreeDeliveryCommand('SKU-123', FreeDeliveryType::Standard);

        self::assertTrue($command->isSkuIdentifier());
    }

    #[Test]
    public function is_sku_identifier_returns_false_for_int(): void
    {
        $command = new SetFreeDeliveryCommand(12345, FreeDeliveryType::Standard);

        self::assertFalse($command->isSkuIdentifier());
    }
}
