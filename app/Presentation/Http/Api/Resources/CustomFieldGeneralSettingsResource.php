<?php

declare(strict_types=1);

namespace App\Presentation\Http\Api\Resources;

use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

/**
 * @mixin CustomFieldGeneralSettings
 */
final class CustomFieldGeneralSettingsResource extends JsonResource
{
    public function __construct(?CustomFieldGeneralSettings $resource)
    {
        parent::__construct($resource);
    }

    /**
     * @return array<string, mixed>
     */
    #[Override]
    public function toArray(Request $request): array
    {
        /** @var CustomFieldGeneralSettings|null $settings */
        $settings = $this->resource;

        return [
            'tooltip' => $settings?->tooltip,
            'select_type' => $settings?->selectType?->value,
            'suggest_common_data' => $settings?->suggestCommonData,
            'admin_only' => $settings === null ? false : $settings->adminOnly,
            'field_validation_rule' => $settings?->validationRule?->value,
        ];
    }
}
