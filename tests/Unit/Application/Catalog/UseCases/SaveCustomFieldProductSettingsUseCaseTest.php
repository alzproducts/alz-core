<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\SaveCustomFieldProductSettingsCommand;
use App\Application\Catalog\UseCases\SaveCustomFieldProductSettingsUseCase;
use App\Application\Contracts\Catalog\CustomFieldProductSettingsRepositoryInterface;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;
use App\Domain\Catalog\CustomFields\Exceptions\ProductSettingsNotApplicableException;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\ValueObjects\Uuid;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SaveCustomFieldProductSettingsUseCase::class)]
final class SaveCustomFieldProductSettingsUseCaseTest extends TestCase
{
    private CustomFieldRepositoryInterface&MockInterface $customFieldRepository;

    private CustomFieldProductSettingsRepositoryInterface&MockInterface $productSettingsRepository;

    private LoggerInterface&MockInterface $logger;

    private SaveCustomFieldProductSettingsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customFieldRepository = Mockery::mock(CustomFieldRepositoryInterface::class);
        $this->productSettingsRepository = Mockery::mock(CustomFieldProductSettingsRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new SaveCustomFieldProductSettingsUseCase(
            $this->customFieldRepository,
            $this->productSettingsRepository,
            $this->logger,
        );
    }

    #[Test]
    public function saves_command_for_product_field_and_returns_refreshed_definition(): void
    {
        $internalId = new Uuid('11111111-2222-3333-4444-555555555555');
        $command = new SaveCustomFieldProductSettingsCommand(
            stockItemUpdateMode: StockItemUpdateMode::AllVariants,
            touchedKeys: ['stock_item_update_mode'],
        );
        $existing = $this->makeDefinition($internalId, CustomFieldItemType::Product);
        $refreshed = $this->makeDefinition($internalId, CustomFieldItemType::Product);

        $this->customFieldRepository
            ->shouldReceive('findByInternalId')
            ->once()
            ->ordered()
            ->with($internalId)
            ->andReturn($existing);

        $this->productSettingsRepository
            ->shouldReceive('save')
            ->once()
            ->ordered()
            ->with($internalId, $command);

        $this->customFieldRepository
            ->shouldReceive('findByInternalId')
            ->once()
            ->ordered()
            ->with($internalId)
            ->andReturn($refreshed);

        $result = $this->useCase->execute($internalId, $command);

        self::assertSame($refreshed, $result);
    }

    #[Test]
    public function throws_product_settings_not_applicable_for_non_product_definition(): void
    {
        $internalId = new Uuid('11111111-2222-3333-4444-555555555555');
        $command = new SaveCustomFieldProductSettingsCommand(
            stockItemUpdateMode: StockItemUpdateMode::Single,
            touchedKeys: ['stock_item_update_mode'],
        );
        $existing = $this->makeDefinition($internalId, CustomFieldItemType::Category);

        $this->customFieldRepository
            ->shouldReceive('findByInternalId')
            ->once()
            ->with($internalId)
            ->andReturn($existing);

        $this->productSettingsRepository->shouldNotReceive('save');

        try {
            $this->useCase->execute($internalId, $command);
            self::fail('Expected ProductSettingsNotApplicableException');
        } catch (ProductSettingsNotApplicableException $e) {
            self::assertSame(42, $e->definitionExternalId);
            self::assertSame(CustomFieldItemType::Category, $e->itemType);
        }
    }

    private function makeDefinition(Uuid $internalId, CustomFieldItemType $itemType): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition(
            internalId: $internalId,
            base: new CustomFieldDefinition(
                id: 42,
                name: 'colour',
                type: CustomFieldType::Text,
                label: 'Colour',
                itemType: $itemType,
                sortOrder: null,
                allowedValues: null,
            ),
            generalSettings: null,
            productSettings: null,
        );
    }
}
