<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\Catalog\CustomFields\ValueObjects;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldValidationRule;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldValueSelectType;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(CustomFieldGeneralSettings::class)]
final class CustomFieldGeneralSettingsTest extends TestCase
{
    #[Test]
    public function constructor_sets_all_properties(): void
    {
        $settings = new CustomFieldGeneralSettings(
            tooltip: 'Shown on hover',
            selectType: CustomFieldValueSelectType::Brand,
            suggestCommonData: true,
            adminOnly: true,
            validationRule: CustomFieldValidationRule::Url,
        );

        self::assertSame('Shown on hover', $settings->tooltip);
        self::assertSame(CustomFieldValueSelectType::Brand, $settings->selectType);
        self::assertTrue($settings->suggestCommonData);
        self::assertTrue($settings->adminOnly);
        self::assertSame(CustomFieldValidationRule::Url, $settings->validationRule);
    }
}
