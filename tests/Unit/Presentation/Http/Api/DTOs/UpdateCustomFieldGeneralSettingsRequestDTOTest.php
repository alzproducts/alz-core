<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\DTOs;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldGeneralSettingsField;
use App\Presentation\Http\Api\DTOs\UpdateCustomFieldGeneralSettingsRequestDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

#[CoversClass(UpdateCustomFieldGeneralSettingsRequestDTO::class)]
final class UpdateCustomFieldGeneralSettingsRequestDTOTest extends TestCase
{
    #[Test]
    public function to_command_has_empty_maps_when_all_fields_absent(): void
    {
        $dto = new UpdateCustomFieldGeneralSettingsRequestDTO();

        $command = $dto->toCommand();

        self::assertSame([], $command->valuesToSet);
        self::assertSame([], $command->columnsToClear);
    }

    #[Test]
    public function to_command_omits_fields_left_as_optional(): void
    {
        $dto = new UpdateCustomFieldGeneralSettingsRequestDTO(
            tooltip: 'Updated tooltip',
        );

        $command = $dto->toCommand();

        self::assertSame(['tooltip' => 'Updated tooltip'], $command->valuesToSet);
        self::assertSame([], $command->columnsToClear);
    }

    #[Test]
    public function to_command_routes_explicit_null_to_columns_to_clear(): void
    {
        $dto = new UpdateCustomFieldGeneralSettingsRequestDTO(
            tooltip: null,
            select_type: null,
            suggest_common_data: null,
            field_validation_rule: null,
        );

        $command = $dto->toCommand();

        self::assertSame([], $command->valuesToSet);
        self::assertSame(
            [
                CustomFieldGeneralSettingsField::Tooltip,
                CustomFieldGeneralSettingsField::SelectType,
                CustomFieldGeneralSettingsField::SuggestCommonData,
                CustomFieldGeneralSettingsField::ValidationRule,
            ],
            $command->columnsToClear,
        );
    }

    #[Test]
    public function to_command_routes_admin_only_to_values_when_explicitly_set(): void
    {
        $dto = new UpdateCustomFieldGeneralSettingsRequestDTO(
            admin_only: true,
        );

        $command = $dto->toCommand();

        self::assertSame(['admin_only' => true], $command->valuesToSet);
        self::assertSame([], $command->columnsToClear);
    }

    #[Test]
    public function to_command_routes_every_set_field_into_values_to_set(): void
    {
        $dto = new UpdateCustomFieldGeneralSettingsRequestDTO(
            tooltip: 'Help text',
            select_type: 'category',
            suggest_common_data: true,
            admin_only: false,
            field_validation_rule: 1,
        );

        $command = $dto->toCommand();

        self::assertSame(
            [
                'tooltip' => 'Help text',
                'select_type' => 'category',
                'suggest_common_data' => true,
                'admin_only' => false,
                'field_validation_rule' => 1,
            ],
            $command->valuesToSet,
        );
        self::assertSame([], $command->columnsToClear);
    }

    #[Test]
    public function defaults_every_field_to_optional_sentinel(): void
    {
        $dto = new UpdateCustomFieldGeneralSettingsRequestDTO();

        self::assertInstanceOf(Optional::class, $dto->tooltip);
        self::assertInstanceOf(Optional::class, $dto->select_type);
        self::assertInstanceOf(Optional::class, $dto->suggest_common_data);
        self::assertInstanceOf(Optional::class, $dto->admin_only);
        self::assertInstanceOf(Optional::class, $dto->field_validation_rule);
    }
}
