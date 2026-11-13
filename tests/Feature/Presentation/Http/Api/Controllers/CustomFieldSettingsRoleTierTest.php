<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Http\Api\Controllers;

use App\Application\Contracts\Catalog\CustomFieldGeneralSettingsRepositoryInterface;
use App\Application\Contracts\Catalog\CustomFieldProductSettingsRepositoryInterface;
use App\Application\Contracts\Catalog\CustomFieldRepositoryInterface;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldItemType;
use App\Domain\Catalog\CustomFields\Enums\CustomFieldType;
use App\Domain\Catalog\CustomFields\ValueObjects\ConfiguredFieldDefinition;
use App\Domain\Catalog\CustomFields\ValueObjects\CustomFieldDefinition;
use App\Domain\ValueObjects\Uuid;
use App\Presentation\Http\Middleware\EnsureRoleTierMiddleware;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Feature\Concerns\SendsRoleTokens;
use Tests\TestCase;

#[CoversClass(EnsureRoleTierMiddleware::class)]
final class CustomFieldSettingsRoleTierTest extends TestCase
{
    use SendsRoleTokens;

    private const string FIXTURE_UUID = '11111111-2222-3333-4444-555555555555';

    private CustomFieldRepositoryInterface&MockInterface $customFieldRepository;

    private CustomFieldGeneralSettingsRepositoryInterface&MockInterface $generalSettingsRepository;

    private CustomFieldProductSettingsRepositoryInterface&MockInterface $productSettingsRepository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->customFieldRepository = Mockery::mock(CustomFieldRepositoryInterface::class);
        $this->generalSettingsRepository = Mockery::mock(CustomFieldGeneralSettingsRepositoryInterface::class);
        $this->productSettingsRepository = Mockery::mock(CustomFieldProductSettingsRepositoryInterface::class);

        $this->app->instance(CustomFieldRepositoryInterface::class, $this->customFieldRepository);
        $this->app->instance(CustomFieldGeneralSettingsRepositoryInterface::class, $this->generalSettingsRepository);
        $this->app->instance(CustomFieldProductSettingsRepositoryInterface::class, $this->productSettingsRepository);

        $this->fakeSupabaseJwks();
    }

    #[Override]
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    /**
     * @param array<string, string> $body
     */
    #[Test]
    #[DataProvider('settingsRouteProvider')]
    public function standard_role_is_rejected_with_403_on_the_settings_put(string $suffix, array $body): void
    {
        $this->customFieldRepository->shouldNotReceive('findByInternalId');
        $this->generalSettingsRepository->shouldNotReceive('save');
        $this->productSettingsRepository->shouldNotReceive('save');

        $this->asRole('standard')->putJson($this->settingsUrl($suffix), $body)
            ->assertForbidden()
            ->assertExactJson(['error' => ['type' => 'forbidden', 'message' => 'Insufficient role.']]);
    }

    /**
     * @param array<string, string> $body
     */
    #[Test]
    #[DataProvider('settingsRouteProvider')]
    public function manager_role_gets_the_settings_put_200_response(string $suffix, array $body): void
    {
        $this->customFieldRepository->shouldReceive('findByInternalId')->andReturn($this->productDefinition());
        $settingsRepository = $suffix === 'general-settings' ? $this->generalSettingsRepository : $this->productSettingsRepository;
        $settingsRepository->shouldReceive('save')->once();

        $this->asRole('manager')->putJson($this->settingsUrl($suffix), $body)
            ->assertOk()
            ->assertJsonPath('data.internal_id', self::FIXTURE_UUID);
    }

    /**
     * @return array<string, array{string, array<string, string>}>
     */
    public static function settingsRouteProvider(): array
    {
        return [
            'general-settings' => ['general-settings', ['tooltip' => 'Helpful tooltip']],
            'product-settings' => ['product-settings', ['stock_item_update_mode' => 'all_variants']],
        ];
    }

    private function settingsUrl(string $suffix): string
    {
        return '/api/catalog/custom-field-definitions/' . self::FIXTURE_UUID . '/' . $suffix;
    }

    private function productDefinition(): ConfiguredFieldDefinition
    {
        return new ConfiguredFieldDefinition(
            internalId: new Uuid(self::FIXTURE_UUID),
            base: new CustomFieldDefinition(
                id: 42,
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
