<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Inventory\Commands;

use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Data\InvalidSkuException;
use App\Domain\Inventory\Commands\UpdateSkuCommand;
use App\Domain\Inventory\Enums\SkuUpdateReason;
use App\Domain\Inventory\Enums\SkuUpdateType;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Webmozart\Assert\InvalidArgumentException;

#[CoversClass(UpdateSkuCommand::class)]
final class UpdateSkuCommandTest extends TestCase
{
    /*
    |--------------------------------------------------------------------------
    | Construction Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function it_constructs_with_provided_type_and_new_sku(): void
    {
        $newSku = Sku::fromTrusted('NEW-SKU-123');

        $command = new UpdateSkuCommand(
            oldSku: 'OLD-SKU-456',
            newSku: $newSku,
            type: SkuUpdateType::Provided,
            reason: SkuUpdateReason::FixSkuMismatch,
        );

        self::assertSame('OLD-SKU-456', $command->oldSku);
        self::assertSame($newSku, $command->newSku);
        self::assertSame(SkuUpdateType::Provided, $command->type);
        self::assertSame(SkuUpdateReason::FixSkuMismatch, $command->reason);
    }

    #[Test]
    public function it_constructs_with_generated_type_without_new_sku(): void
    {
        $command = new UpdateSkuCommand(
            oldSku: 'OLD-SKU-789',
            newSku: null,
            type: SkuUpdateType::Generated,
            reason: SkuUpdateReason::ShortenLongSku,
        );

        self::assertSame('OLD-SKU-789', $command->oldSku);
        self::assertNull($command->newSku);
        self::assertSame(SkuUpdateType::Generated, $command->type);
        self::assertSame(SkuUpdateReason::ShortenLongSku, $command->reason);
    }

    #[Test]
    public function it_rejects_empty_old_sku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('oldSku cannot be empty');

        new UpdateSkuCommand(
            oldSku: '',
            newSku: null,
            type: SkuUpdateType::Generated,
            reason: SkuUpdateReason::Other,
        );
    }

    #[Test]
    public function it_rejects_whitespace_only_old_sku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('oldSku cannot be empty');

        new UpdateSkuCommand(
            oldSku: '   ',
            newSku: null,
            type: SkuUpdateType::Generated,
            reason: SkuUpdateReason::Other,
        );
    }

    #[Test]
    public function it_rejects_provided_type_without_new_sku(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('newSku is required when type is Provided');

        new UpdateSkuCommand(
            oldSku: 'OLD-SKU',
            newSku: null,
            type: SkuUpdateType::Provided,
            reason: SkuUpdateReason::StandardizeFormat,
        );
    }

    /*
    |--------------------------------------------------------------------------
    | Factory Method Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function provided_factory_creates_correct_command(): void
    {
        $newSku = Sku::fromTrusted('FACTORY-NEW');

        $command = UpdateSkuCommand::provided(
            oldSku: 'FACTORY-OLD',
            newSku: $newSku,
            reason: SkuUpdateReason::MergeProducts,
        );

        self::assertSame('FACTORY-OLD', $command->oldSku);
        self::assertSame($newSku, $command->newSku);
        self::assertSame(SkuUpdateType::Provided, $command->type);
        self::assertSame(SkuUpdateReason::MergeProducts, $command->reason);
    }

    #[Test]
    public function generated_factory_creates_correct_command(): void
    {
        $command = UpdateSkuCommand::generated(
            oldSku: 'AUTOGEN-OLD',
            reason: SkuUpdateReason::ShortenLongSku,
        );

        self::assertSame('AUTOGEN-OLD', $command->oldSku);
        self::assertNull($command->newSku);
        self::assertSame(SkuUpdateType::Generated, $command->type);
        self::assertSame(SkuUpdateReason::ShortenLongSku, $command->reason);
    }

    /*
    |--------------------------------------------------------------------------
    | getProvidedSku() Tests
    |--------------------------------------------------------------------------
    */

    #[Test]
    public function get_provided_sku_returns_sku_when_present(): void
    {
        $newSku = Sku::fromTrusted('GET-ME');
        $command = UpdateSkuCommand::provided('OLD', $newSku, SkuUpdateReason::Other);

        $result = $command->getProvidedSku();

        self::assertSame($newSku, $result);
    }

    #[Test]
    public function get_provided_sku_throws_when_null(): void
    {
        $command = UpdateSkuCommand::generated('OLD', SkuUpdateReason::Other);

        $this->expectException(InvalidSkuException::class);
        $this->expectExceptionMessage('newSku is required when update type is Provided');

        $command->getProvidedSku();
    }
}
