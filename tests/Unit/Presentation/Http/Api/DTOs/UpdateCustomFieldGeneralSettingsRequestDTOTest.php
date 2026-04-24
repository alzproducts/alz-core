<?php

declare(strict_types=1);

namespace Tests\Unit\Presentation\Http\Api\DTOs;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;
use App\Presentation\Http\Api\DTOs\UpdateCustomFieldGeneralSettingsRequestDTO;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Spatie\LaravelData\Optional;
use Tests\TestCase;

#[CoversClass(UpdateCustomFieldGeneralSettingsRequestDTO::class)]
final class UpdateCustomFieldGeneralSettingsRequestDTOTest extends TestCase
{
    #[Test]
    public function to_command_has_empty_touched_keys_when_all_fields_absent(): void
    {
        $dto = new UpdateCustomFieldGeneralSettingsRequestDTO();

        $command = $dto->toCommand();

        self::assertSame([], $command->touchedKeys);
        self::assertNull($command->tooltip);
        self::assertNull($command->selectType);
        self::assertNull($command->suggestCommonData);
        self::assertNull($command->adminOnly);
        self::assertNull($command->validationRule);
    }

    #[Test]
    public function to_command_omits_fields_left_as_optional(): void
    {
        $dto = new UpdateCustomFieldGeneralSettingsRequestDTO(
            tooltip: 'Updated tooltip',
        );

        $command = $dto->toCommand();

        self::assertSame(['tooltip'], $command->touchedKeys);
        self::assertSame('Updated tooltip', $command->tooltip);
    }

    #[Test]
    public function to_command_preserves_explicit_null_for_nullable_fields(): void
    {
        $dto = new UpdateCustomFieldGeneralSettingsRequestDTO(
            tooltip: null,
            select_type: null,
            suggest_common_data: null,
            field_validation_rule: null,
        );

        $command = $dto->toCommand();

        self::assertSame(
            ['tooltip', 'select_type', 'suggest_common_data', 'field_validation_rule'],
            $command->touchedKeys,
        );
        self::assertNull($command->tooltip);
        self::assertNull($command->selectType);
        self::assertNull($command->suggestCommonData);
        self::assertNull($command->validationRule);
    }

    #[Test]
    public function to_command_includes_admin_only_when_explicitly_set(): void
    {
        $dto = new UpdateCustomFieldGeneralSettingsRequestDTO(
            admin_only: true,
        );

        $command = $dto->toCommand();

        self::assertSame(['admin_only'], $command->touchedKeys);
        self::assertTrue($command->adminOnly);
    }

    #[Test]
    public function to_command_resolves_enums_and_reports_all_touched_keys_when_all_fields_set(): void
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
            ['tooltip', 'select_type', 'suggest_common_data', 'admin_only', 'field_validation_rule'],
            $command->touchedKeys,
        );
        self::assertSame('Help text', $command->tooltip);
        self::assertSame(CustomFieldValueSelectType::Category, $command->selectType);
        self::assertTrue($command->suggestCommonData);
        self::assertFalse($command->adminOnly);
        self::assertSame(CustomFieldValidationRule::Url, $command->validationRule);
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
