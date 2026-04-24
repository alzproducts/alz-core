<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\DTOs;

use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;
use App\Presentation\Http\Api\DTOs\UpdateCustomFieldProductSettingsRequestDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

#[CoversClass(UpdateCustomFieldProductSettingsRequestDTO::class)]
final class UpdateCustomFieldProductSettingsRequestDTOTest extends TestCase
{
    #[Test]
    public function to_command_has_empty_touched_keys_when_field_absent(): void
    {
        $dto = new UpdateCustomFieldProductSettingsRequestDTO();

        $command = $dto->toCommand();

        self::assertSame([], $command->touchedKeys);
        self::assertNull($command->stockItemUpdateMode);
    }

    #[Test]
    public function to_command_preserves_explicit_null(): void
    {
        $dto = new UpdateCustomFieldProductSettingsRequestDTO(
            stock_item_update_mode: null,
        );

        $command = $dto->toCommand();

        self::assertSame(['stock_item_update_mode'], $command->touchedKeys);
        self::assertNull($command->stockItemUpdateMode);
    }

    #[Test]
    public function to_command_resolves_enum_when_value_set(): void
    {
        $dto = new UpdateCustomFieldProductSettingsRequestDTO(
            stock_item_update_mode: 'single',
        );

        $command = $dto->toCommand();

        self::assertSame(['stock_item_update_mode'], $command->touchedKeys);
        self::assertSame(StockItemUpdateMode::Single, $command->stockItemUpdateMode);
    }

    #[Test]
    public function defaults_field_to_optional_sentinel(): void
    {
        $dto = new UpdateCustomFieldProductSettingsRequestDTO();

        self::assertInstanceOf(Optional::class, $dto->stock_item_update_mode);
    }
}
