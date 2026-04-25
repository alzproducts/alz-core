<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\DTOs;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldProductSettingsField;
use App\Presentation\Http\Api\DTOs\UpdateCustomFieldProductSettingsRequestDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

#[CoversClass(UpdateCustomFieldProductSettingsRequestDTO::class)]
final class UpdateCustomFieldProductSettingsRequestDTOTest extends TestCase
{
    #[Test]
    public function to_command_has_empty_maps_when_field_absent(): void
    {
        $dto = new UpdateCustomFieldProductSettingsRequestDTO();

        $command = $dto->toCommand();

        self::assertSame([], $command->valuesToSet);
        self::assertSame([], $command->columnsToClear);
    }

    #[Test]
    public function to_command_routes_explicit_null_to_columns_to_clear(): void
    {
        $dto = new UpdateCustomFieldProductSettingsRequestDTO(
            stock_item_update_mode: null,
        );

        $command = $dto->toCommand();

        self::assertSame([], $command->valuesToSet);
        self::assertSame(
            [CustomFieldProductSettingsField::StockItemUpdateMode],
            $command->columnsToClear,
        );
    }

    #[Test]
    public function to_command_routes_value_to_values_to_set(): void
    {
        $dto = new UpdateCustomFieldProductSettingsRequestDTO(
            stock_item_update_mode: 'single',
        );

        $command = $dto->toCommand();

        self::assertSame(['stock_item_update_mode' => 'single'], $command->valuesToSet);
        self::assertSame([], $command->columnsToClear);
    }

    #[Test]
    public function defaults_field_to_optional_sentinel(): void
    {
        $dto = new UpdateCustomFieldProductSettingsRequestDTO();

        self::assertInstanceOf(Optional::class, $dto->stock_item_update_mode);
    }
}
