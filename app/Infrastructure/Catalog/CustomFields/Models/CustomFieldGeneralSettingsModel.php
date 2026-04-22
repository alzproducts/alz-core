<?php

declare(strict_types=1);

namespace App\Infrastructure\Catalog\CustomFields\Models;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Infrastructure\Contracts\EloquentDomainMappableInterface;
use App\Infrastructure\Shopwired\Models\CustomFieldDefinitionModel;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Eloquent model for catalog.custom_field_general_settings.
 *
 * Local presentation/behaviour overrides for any ShopWired custom field definition.
 * One-to-one with {@see CustomFieldDefinitionModel}; enforced by a unique FK.
 *
 * @property string $id Internal UUID
 * @property string $custom_field_definition_id FK to shopwired.custom_field_definitions.id
 * @property string|null $tooltip
 * @property CustomFieldValueSelectType|null $select_type
 * @property bool|null $suggest_common_data
 * @property bool $admin_only
 * @property CustomFieldValidationRule|null $field_validation_rule
 * @property CarbonImmutable $created_at
 * @property CarbonImmutable $updated_at
 *
 * @implements EloquentDomainMappableInterface<CustomFieldGeneralSettings>
 */
final class CustomFieldGeneralSettingsModel extends Model implements EloquentDomainMappableInterface
{
    use HasUuids;

    protected $table = 'catalog.custom_field_general_settings';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'select_type' => CustomFieldValueSelectType::class,
            'suggest_common_data' => 'boolean',
            'admin_only' => 'boolean',
            'field_validation_rule' => CustomFieldValidationRule::class,
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<CustomFieldDefinitionModel, $this>
     */
    public function customFieldDefinition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinitionModel::class, 'custom_field_definition_id', 'id');
    }

    public function toDomain(): CustomFieldGeneralSettings
    {
        return new CustomFieldGeneralSettings(
            tooltip: $this->tooltip,
            selectType: $this->select_type,
            suggestCommonData: $this->suggest_common_data,
            adminOnly: $this->admin_only,
            validationRule: $this->field_validation_rule,
        );
    }

    /**
     * Convert a Domain CustomFieldGeneralSettings to Eloquent attributes.
     *
     * Does NOT include `custom_field_definition_id`; the caller (repository) owns
     * setting the FK so this mapper stays symmetric with other ShopWired models.
     *
     * @return array<string, mixed>
     */
    public static function fromDomainAttributes(object $entity): array
    {
        /** @var CustomFieldGeneralSettings $entity */
        return [
            'tooltip' => $entity->tooltip,
            'select_type' => $entity->selectType?->value,
            'suggest_common_data' => $entity->suggestCommonData,
            'admin_only' => $entity->adminOnly,
            'field_validation_rule' => $entity->validationRule?->value,
        ];
    }
}
