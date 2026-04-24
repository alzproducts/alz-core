<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Catalog\Commands\SaveCustomFieldGeneralSettingsCommand;
use App\Application\Contracts\Catalog\CustomFieldGeneralSettingsRepositoryInterface;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldGeneralSettings;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\ValueObjects\Uuid;
use App\Presentation\Http\Api\Controllers\CustomFieldGeneralSettingsController;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(CustomFieldGeneralSettingsController::class)]
final class CustomFieldGeneralSettingsControllerTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private CustomFieldRepositoryInterface&MockInterface $customFieldRepository;

    private CustomFieldGeneralSettingsRepositoryInterface&MockInterface $generalSettingsRepository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->customFieldRepository = Mockery::mock(CustomFieldRepositoryInterface::class);
        $this->generalSettingsRepository = Mockery::mock(CustomFieldGeneralSettingsRepositoryInterface::class);

        $this->app->instance(CustomFieldRepositoryInterface::class, $this->customFieldRepository);
        $this->app->instance(CustomFieldGeneralSettingsRepositoryInterface::class, $this->generalSettingsRepository);
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    #[Test]
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->putJson('/api/catalog/custom-field-definitions/42/general-settings', ['tooltip' => 'Hi']);

        $response->assertStatus(401);
    }

    #[Test]
    public function put_upserts_touched_fields_and_returns_enriched_definition(): void
    {
        $definitionExternalId = 42;
        $internalId = new Uuid('11111111-2222-3333-4444-555555555555');

        $this->customFieldRepository
            ->shouldReceive('findInternalIdByExternalId')
            ->once()
            ->with($definitionExternalId)
            ->andReturn($internalId);

        $this->generalSettingsRepository
            ->shouldReceive('save')
            ->once()
            ->with(
                $internalId,
                Mockery::on(static fn(SaveCustomFieldGeneralSettingsCommand $c): bool => $c->tooltip === 'Helpful tooltip'
                    && $c->touchedKeys === ['tooltip']),
            );

        $this->customFieldRepository
            ->shouldReceive('findByExternalId')
            ->once()
            ->with($definitionExternalId)
            ->andReturn($this->makeDefinitionWithTooltip($definitionExternalId, 'Helpful tooltip'));

        $response = $this->asApprovedUser()->putJson(
            '/api/catalog/custom-field-definitions/42/general-settings',
            ['tooltip' => 'Helpful tooltip'],
        );

        $response->assertStatus(200);
        $body = $response->json();
        self::assertSame($definitionExternalId, $body['data']['id']);
        self::assertSame('Helpful tooltip', $body['data']['general']['tooltip']);
        self::assertFalse($body['data']['general']['admin_only']);
    }

    #[Test]
    public function put_returns_404_when_definition_not_found(): void
    {
        $this->customFieldRepository
            ->shouldReceive('findInternalIdByExternalId')
            ->once()
            ->with(999)
            ->andThrow(new RecordNotFoundException('custom_field_definition', 999));

        $this->generalSettingsRepository->shouldNotReceive('save');

        $response = $this->asApprovedUser()->putJson(
            '/api/catalog/custom-field-definitions/999/general-settings',
            ['tooltip' => 'ignored'],
        );

        $response->assertStatus(404);
        self::assertSame('not_found', $response->json('error.type'));
    }

    #[Test]
    public function invalid_select_type_enum_returns_422(): void
    {
        $this->customFieldRepository->shouldNotReceive('findInternalIdByExternalId');
        $this->generalSettingsRepository->shouldNotReceive('save');

        $response = $this->asApprovedUser()->putJson(
            '/api/catalog/custom-field-definitions/42/general-settings',
            ['select_type' => 'not-a-valid-enum'],
        );

        $response->assertStatus(422);
        self::assertSame('validation_error', $response->json('error.type'));
    }

    private function makeDefinitionWithTooltip(int $id, string $tooltip): ConfiguredFieldDefinition
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
            generalSettings: new CustomFieldGeneralSettings(
                tooltip: $tooltip,
                selectType: null,
                suggestCommonData: null,
                adminOnly: false,
                validationRule: null,
            ),
            productSettings: null,
        );
    }
}
