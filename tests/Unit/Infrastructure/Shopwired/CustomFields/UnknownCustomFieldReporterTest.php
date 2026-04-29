<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\Shopwired\CustomFields;

use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Infrastructure\Shopwired\CustomFields\UnknownCustomFieldReporter;
use Illuminate\Support\Facades\Log;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(UnknownCustomFieldReporter::class)]
final class UnknownCustomFieldReporterTest extends TestCase
{
    private UnknownCustomFieldReporter $reporter;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->reporter = new UnknownCustomFieldReporter;
    }

    #[Test]
    public function emits_single_summary_when_one_field_recorded(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Unknown custom fields encountered - definitions out of sync with ShopWired',
                ['by_item_type' => ['product' => ['color_swatch' => 1]]],
            );

        $this->reporter->record(CustomFieldItemType::Product, 'color_swatch');

        $this->app->terminate();
    }

    #[Test]
    public function counts_repeats_of_same_field(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Unknown custom fields encountered - definitions out of sync with ShopWired',
                ['by_item_type' => ['product' => ['color_swatch' => 3]]],
            );

        $this->reporter->record(CustomFieldItemType::Product, 'color_swatch');
        $this->reporter->record(CustomFieldItemType::Product, 'color_swatch');
        $this->reporter->record(CustomFieldItemType::Product, 'color_swatch');

        $this->app->terminate();
    }

    #[Test]
    public function aggregates_distinct_item_types_in_one_summary(): void
    {
        Log::shouldReceive('warning')
            ->once()
            ->with(
                'Unknown custom fields encountered - definitions out of sync with ShopWired',
                ['by_item_type' => [
                    'product' => ['color_swatch' => 2, 'gtin_alt' => 1],
                    'category' => ['hero_banner' => 1],
                    'brand' => ['logo_alt' => 1],
                ]],
            );

        $this->reporter->record(CustomFieldItemType::Product, 'color_swatch');
        $this->reporter->record(CustomFieldItemType::Product, 'gtin_alt');
        $this->reporter->record(CustomFieldItemType::Product, 'color_swatch');
        $this->reporter->record(CustomFieldItemType::Category, 'hero_banner');
        $this->reporter->record(CustomFieldItemType::Brand, 'logo_alt');

        $this->app->terminate();
    }

    #[Test]
    public function emits_nothing_when_never_recorded(): void
    {
        Log::shouldReceive('warning')->never();

        $this->app->terminate();
    }
}
