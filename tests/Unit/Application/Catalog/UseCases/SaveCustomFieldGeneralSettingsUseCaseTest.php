<?php

declare(strict_types=1);

namespace Tests\Unit\Application\Catalog\UseCases;

use App\Application\Catalog\Commands\SaveCustomFieldGeneralSettingsCommand;
use App\Application\Catalog\UseCases\SaveCustomFieldGeneralSettingsUseCase;
use App\Application\Contracts\Catalog\CustomFieldGeneralSettingsRepositoryInterface;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\ValueObjects\Uuid;
use Mockery;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

#[CoversClass(SaveCustomFieldGeneralSettingsUseCase::class)]
final class SaveCustomFieldGeneralSettingsUseCaseTest extends TestCase
{
    private CustomFieldRepositoryInterface&MockInterface $customFieldRepository;

    private CustomFieldGeneralSettingsRepositoryInterface&MockInterface $generalSettingsRepository;

    private LoggerInterface&MockInterface $logger;

    private SaveCustomFieldGeneralSettingsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->customFieldRepository = Mockery::mock(CustomFieldRepositoryInterface::class);
        $this->generalSettingsRepository = Mockery::mock(CustomFieldGeneralSettingsRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->logger->shouldReceive('info')->byDefault();

        $this->useCase = new SaveCustomFieldGeneralSettingsUseCase(
            $this->customFieldRepository,
            $this->generalSettingsRepository,
            $this->logger,
        );
    }

    #[Test]
    public function resolves_internal_id_saves_command_and_returns_enriched_definition(): void
    {
        $definitionExternalId = 42;
        $internalId = new Uuid('11111111-2222-3333-4444-555555555555');
        $command = new SaveCustomFieldGeneralSettingsCommand(
            tooltip: 'Helpful tooltip',
            selectType: null,
            suggestCommonData: null,
            adminOnly: null,
            validationRule: null,
            touchedKeys: ['tooltip'],
        );
        $refreshed = $this->makeDefinition($definitionExternalId);

        $this->customFieldRepository
            ->shouldReceive('findInternalIdByExternalId')
            ->once()
            ->with($definitionExternalId)
            ->andReturn($internalId);

        $this->generalSettingsRepository
            ->shouldReceive('save')
            ->once()
            ->with($internalId, $command);

        $this->customFieldRepository
            ->shouldReceive('findByExternalId')
            ->once()
            ->with($definitionExternalId)
            ->andReturn($refreshed);

        $result = $this->useCase->execute($definitionExternalId, $command);

        self::assertSame($refreshed, $result);
    }

    private function makeDefinition(int $id): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition(
            base: new CustomFieldDefinition(
                id: $id,
                name: 'colour',
                type: CustomFieldType::Text,
                label: 'Colour',
                itemType: CustomFieldItemType::Product,
                sortOrder: null,
                allowedValues: null,
            ),
            generalSettings: null,
            productSettings: null,
        );
    }
}
