<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Catalog\Commands\SaveCustomFieldProductSettingsCommand;
use App\Application\Catalog\Results\CustomFieldResolutionResult;
use App\Application\Contracts\Catalog\CustomFieldProductSettingsRepositoryInterface;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\Enums\StockItemUpdateMode;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\ProductFieldSettings;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\ValueObjects\Uuid;
use App\Presentation\Http\Api\Controllers\CustomFieldProductSettingsController;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\AuthenticatesAsApprovedUser;
use Tests\TestCase;

#[CoversClass(CustomFieldProductSettingsController::class)]
final class CustomFieldProductSettingsControllerTest extends TestCase
{
    use AuthenticatesAsApprovedUser;

    private CustomFieldRepositoryInterface&MockInterface $customFieldRepository;

    private CustomFieldProductSettingsRepositoryInterface&MockInterface $productSettingsRepository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->customFieldRepository = Mockery::mock(CustomFieldRepositoryInterface::class);
        $this->productSettingsRepository = Mockery::mock(CustomFieldProductSettingsRepositoryInterface::class);

        $this->app->instance(CustomFieldRepositoryInterface::class, $this->customFieldRepository);
        $this->app->instance(CustomFieldProductSettingsRepositoryInterface::class, $this->productSettingsRepository);
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
        $response = $this->putJson(
            '/api/catalog/custom-field-definitions/42/product-settings',
            ['stock_item_update_mode' => 'single'],
        );

        $response->assertStatus(401);
    }

    #[Test]
    public function put_upserts_touched_fields_for_product_definition_and_returns_enriched_definition(): void
    {
        $definitionExternalId = 42;
        $internalId = new Uuid('11111111-2222-3333-4444-555555555555');
        $productDefinition = $this->makeDefinition($definitionExternalId, CustomFieldItemType::Product, null);
        $refreshed = $this->makeDefinition(
            $definitionExternalId,
            CustomFieldItemType::Product,
            StockItemUpdateMode::AllVariants,
        );

        $this->customFieldRepository
            ->shouldReceive('findEnrichedWithInternalId')
            ->once()
            ->with($definitionExternalId)
            ->andReturn(new CustomFieldResolutionResult($internalId, $productDefinition));

        $this->productSettingsRepository
            ->shouldReceive('save')
            ->once()
            ->with(
                $internalId,
                Mockery::on(static fn(SaveCustomFieldProductSettingsCommand $c): bool => $c->stockItemUpdateMode === StockItemUpdateMode::AllVariants
                    && $c->touchedKeys === ['stock_item_update_mode']),
            );

        $this->customFieldRepository
            ->shouldReceive('findByExternalId')
            ->once()
            ->with($definitionExternalId)
            ->andReturn($refreshed);

        $response = $this->asApprovedUser()->putJson(
            '/api/catalog/custom-field-definitions/42/product-settings',
            ['stock_item_update_mode' => 'all_variants'],
        );

        $response->assertStatus(200);
        $body = $response->json();
        self::assertSame('all_variants', $body['data']['product']['stock_item_update_mode']);
    }

    #[Test]
    public function put_returns_422_when_definition_is_not_product_type(): void
    {
        $definitionExternalId = 42;
        $internalId = new Uuid('11111111-2222-3333-4444-555555555555');
        $nonProduct = $this->makeDefinition($definitionExternalId, CustomFieldItemType::Category, null);

        $this->customFieldRepository
            ->shouldReceive('findEnrichedWithInternalId')
            ->once()
            ->with($definitionExternalId)
            ->andReturn(new CustomFieldResolutionResult($internalId, $nonProduct));

        $this->productSettingsRepository->shouldNotReceive('save');

        $response = $this->asApprovedUser()->putJson(
            '/api/catalog/custom-field-definitions/42/product-settings',
            ['stock_item_update_mode' => 'single'],
        );

        $response->assertStatus(422);
        $body = $response->json();
        self::assertSame('validation_error', $body['error']['type']);
        self::assertSame('product_settings_not_applicable', $body['error']['errors']['code']);
        self::assertSame($definitionExternalId, $body['error']['errors']['definition_id']);
        self::assertSame('category', $body['error']['errors']['item_type']);
    }

    #[Test]
    public function put_returns_404_when_definition_not_found(): void
    {
        $this->customFieldRepository
            ->shouldReceive('findEnrichedWithInternalId')
            ->once()
            ->with(999)
            ->andThrow(new RecordNotFoundException('custom_field_definition', 999));

        $this->productSettingsRepository->shouldNotReceive('save');

        $response = $this->asApprovedUser()->putJson(
            '/api/catalog/custom-field-definitions/999/product-settings',
            ['stock_item_update_mode' => 'single'],
        );

        $response->assertStatus(404);
        self::assertSame('not_found', $response->json('error.type'));
    }

    private function makeDefinition(
        int $id,
        CustomFieldItemType $itemType,
        ?StockItemUpdateMode $stockItemUpdateMode,
    ): ConfiguredFieldDefinition {
        return new ConfiguredFieldDefinition(
            base: new CustomFieldDefinition(
                id: $id,
                name: 'colour',
                type: CustomFieldType::Text,
                label: 'Colour',
                itemType: $itemType,
                sortOrder: null,
                allowedValues: null,
            ),
            generalSettings: null,
            productSettings: $itemType === CustomFieldItemType::Product && $stockItemUpdateMode !== null
                ? new ProductFieldSettings($stockItemUpdateMode)
                : null,
        );
    }
}
