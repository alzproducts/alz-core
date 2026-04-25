<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Catalog\Commands\SaveCustomFieldProductSettingsCommand;
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

    private const string FIXTURE_UUID = '11111111-2222-3333-4444-555555555555';

    private const string FIXTURE_UUID_MISSING = '00000000-0000-0000-0000-000000000000';

    #[Test]
    public function unauthenticated_request_returns_401(): void
    {
        $response = $this->putJson(
            '/api/catalog/custom-field-definitions/' . self::FIXTURE_UUID . '/product-settings',
            ['stock_item_update_mode' => 'single'],
        );

        $response->assertStatus(401);
    }

    #[Test]
    public function put_upserts_touched_fields_for_product_definition_and_returns_enriched_definition(): void
    {
        $internalId = new Uuid(self::FIXTURE_UUID);
        $matchesInternalId = Mockery::on(static fn(Uuid $u): bool => $u->value === self::FIXTURE_UUID);
        $productDefinition = $this->makeDefinition($internalId, CustomFieldItemType::Product, null);
        $refreshed = $this->makeDefinition(
            $internalId,
            CustomFieldItemType::Product,
            StockItemUpdateMode::AllVariants,
        );

        $this->customFieldRepository
            ->shouldReceive('findByInternalId')
            ->once()
            ->ordered()
            ->with($matchesInternalId)
            ->andReturn($productDefinition);

        $this->productSettingsRepository
            ->shouldReceive('save')
            ->once()
            ->ordered()
            ->with(
                $matchesInternalId,
                Mockery::on(static fn(SaveCustomFieldProductSettingsCommand $c): bool => $c->stockItemUpdateMode === StockItemUpdateMode::AllVariants
                    && $c->touchedKeys === ['stock_item_update_mode']),
            );

        $this->customFieldRepository
            ->shouldReceive('findByInternalId')
            ->once()
            ->ordered()
            ->with($matchesInternalId)
            ->andReturn($refreshed);

        $response = $this->asApprovedUser()->putJson(
            '/api/catalog/custom-field-definitions/' . self::FIXTURE_UUID . '/product-settings',
            ['stock_item_update_mode' => 'all_variants'],
        );

        $response->assertStatus(200);
        $body = $response->json();
        self::assertSame(self::FIXTURE_UUID, $body['data']['internal_id']);
        self::assertSame('all_variants', $body['data']['product']['stock_item_update_mode']);
    }

    #[Test]
    public function put_returns_422_when_definition_is_not_product_type(): void
    {
        $internalId = new Uuid(self::FIXTURE_UUID);
        $nonProduct = $this->makeDefinition($internalId, CustomFieldItemType::Category, null);

        $this->customFieldRepository
            ->shouldReceive('findByInternalId')
            ->once()
            ->with(Mockery::on(static fn(Uuid $u): bool => $u->value === self::FIXTURE_UUID))
            ->andReturn($nonProduct);

        $this->productSettingsRepository->shouldNotReceive('save');

        $response = $this->asApprovedUser()->putJson(
            '/api/catalog/custom-field-definitions/' . self::FIXTURE_UUID . '/product-settings',
            ['stock_item_update_mode' => 'single'],
        );

        $response->assertStatus(422);
        $body = $response->json();
        self::assertSame('validation_error', $body['error']['type']);
        self::assertSame('product_settings_not_applicable', $body['error']['errors']['code']);
        self::assertSame(42, $body['error']['errors']['definition_id']);
        self::assertSame('category', $body['error']['errors']['item_type']);
    }

    #[Test]
    public function put_returns_404_when_definition_not_found(): void
    {
        $this->customFieldRepository
            ->shouldReceive('findByInternalId')
            ->once()
            ->with(Mockery::on(static fn(Uuid $u): bool => $u->value === self::FIXTURE_UUID_MISSING))
            ->andThrow(new RecordNotFoundException('custom_field_definition', self::FIXTURE_UUID_MISSING));

        $this->productSettingsRepository->shouldNotReceive('save');

        $response = $this->asApprovedUser()->putJson(
            '/api/catalog/custom-field-definitions/' . self::FIXTURE_UUID_MISSING . '/product-settings',
            ['stock_item_update_mode' => 'single'],
        );

        $response->assertStatus(404);
        self::assertSame('not_found', $response->json('error.type'));
    }

    private function makeDefinition(
        Uuid $internalId,
        CustomFieldItemType $itemType,
        ?StockItemUpdateMode $stockItemUpdateMode,
    ): ConfiguredFieldDefinition {
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
            productSettings: $itemType === CustomFieldItemType::Product && $stockItemUpdateMode !== null
                ? new ProductFieldSettings($stockItemUpdateMode)
                : null,
        );
    }
}
