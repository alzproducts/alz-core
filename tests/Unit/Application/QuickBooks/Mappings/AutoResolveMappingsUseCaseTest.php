<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\Mappings;

use App\Application\Contracts\QuickBooks\QuickBooksEnvironmentInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingPickDTO;
use App\Application\QuickBooks\Mappings\DTOs\UnmatchedMappingDTO;
use App\Application\QuickBooks\Mappings\Resolvers\MappingAutoTargetResolver;
use App\Application\QuickBooks\Mappings\Results\MappingAutoResolveResult;
use App\Application\QuickBooks\Mappings\Services\MappingCandidateService;
use App\Application\QuickBooks\Mappings\UseCases\AutoResolveMappingsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\RecordMappingPickUseCase;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(AutoResolveMappingsUseCase::class)]
#[CoversClass(MappingAutoTargetResolver::class)]
#[CoversClass(MappingAutoResolveResult::class)]
#[CoversClass(UnmatchedMappingDTO::class)]
final class AutoResolveMappingsUseCaseTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const string REALM = '9130';

    private const array EXISTING = [
        'tax.standard' => '20.0% S',
        'tax.zero' => '0.0% Z',
        'tax.eu_goods' => '0.0% ECG',
        'tax.eu_shipping' => '0.0% ECS',
        'custom_field.po_number' => 'P.O. Number',
        'custom_field.transaction_id' => 'Transaction ID',
    ];

    private MockInterface&QuickBooksMappingRepositoryInterface $mappings;

    private MockInterface&QuickBooksMappingCandidatesInterface $adapter;

    private MockInterface&QuickBooksTokenService $tokens;

    /** @var array<string, array{string, string|null, string}> */
    private array $recorded = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mappings = Mockery::mock(QuickBooksMappingRepositoryInterface::class);
        $this->adapter = Mockery::mock(QuickBooksMappingCandidatesInterface::class);
        $this->tokens = Mockery::mock(QuickBooksTokenService::class);
    }

    #[Test]
    public function outside_the_sandbox_it_is_refused_before_any_token_adapter_or_repository_call(): void
    {
        $this->assertEquals(new MappingAutoResolveResult(refused: true), $this->useCase(sandbox: false)->execute());
    }

    #[Test]
    public function every_key_resolves_from_an_existing_code_or_field_or_a_synthetic_entity_of_its_own(): void
    {
        $created = [];
        $this->unresolved(MappingKey::cases());
        $this->adapter->shouldReceive('candidates')
            ->times(\count(MappingEntity::cases()))
            ->andReturnUsing(static fn(ApiKeyToken $token, MappingEntity $entity): array => self::existing($entity));
        $this->adapter->shouldReceive('create')->andReturnUsing(
            static function (ApiKeyToken $token, MappingEntity $entity, string $name) use (&$created): MappingCandidateDTO {
                $created[] = "{$entity->value}: {$name}";

                return new MappingCandidateDTO("new {$name}", $name, true);
            },
        );

        $result = $this->useCase()->execute();

        $this->assertEquals(new MappingAutoResolveResult(false, MappingKey::cases(), []), $result);
        $this->assertCount(9, \array_unique($created));
        $this->assertCount(9, $created);
        foreach (MappingKey::cases() as $key) {
            [$id, $name, $realm] = $this->recorded[$key->value];
            $existing = self::EXISTING[$key->value] ?? null;
            $this->assertSame($existing === null ? "new {$name}" : "id {$existing}", $id);
            $this->assertSame(self::REALM, $realm);
            $this->assertIsString($name);
            $this->assertMatchesRegularExpression(
                $existing === null ? '/^Sync Test .{1,21}$/' : '/^' . \preg_quote($existing, '/') . '$/',
                $name,
            );
        }
    }

    /**
     * @return array<string, array{list<MappingCandidateDTO>, MappingKey, string}>
     */
    public static function unmatchable(): array
    {
        return [
            'no tax code' => [[], MappingKey::TaxZero, 'no active match for "0.0% Z"'],
            'no custom field' => [[], MappingKey::PoNumberField, 'no active match for "P.O. Number"'],
            'several tax codes' => [
                [new MappingCandidateDTO('1', '20.0% S', true), new MappingCandidateDTO('2', '20.0% S', true)],
                MappingKey::TaxStandard,
                'several active matches for "20.0% S"',
            ],
            'disabled custom field' => [
                [new MappingCandidateDTO('1', 'Transaction ID', false)],
                MappingKey::TransactionIdField,
                'the match for "Transaction ID" is inactive',
            ],
            'inactive synthetic entity' => [
                [new MappingCandidateDTO('1', 'Sync Test Department', false)],
                MappingKey::Department,
                'the match for "Sync Test Department" is inactive',
            ],
            'several synthetic entities' => [
                [new MappingCandidateDTO('1', 'Sync Test Class', true), new MappingCandidateDTO('2', 'Sync Test Class', true)],
                MappingKey::SalesClass,
                'several active matches for "Sync Test Class"',
            ],
        ];
    }

    /**
     * @param list<MappingCandidateDTO> $candidates
     */
    #[Test]
    #[DataProvider('unmatchable')]
    public function an_unmatchable_key_is_named_with_its_reason_and_nothing_is_created_or_recorded(array $candidates, MappingKey $key, string $reason): void
    {
        $this->unresolved([$key]);
        $this->adapter->shouldReceive('candidates')->andReturn($candidates);

        $this->assertEquals(
            new MappingAutoResolveResult(false, [], [new UnmatchedMappingDTO($key, $reason)]),
            $this->useCase()->execute(),
        );
        $this->assertSame([], $this->recorded);
    }

    #[Test]
    public function an_existing_synthetic_entity_is_recorded_without_a_create_though_an_inactive_one_shares_its_name(): void
    {
        $this->unresolved([MappingKey::DefaultTerms]);
        $this->adapter->shouldReceive('candidates')->andReturn([
            new MappingCandidateDTO('1', 'Sync Test Terms', false),
            new MappingCandidateDTO('2', 'Sync Test Terms', true),
            new MappingCandidateDTO('3', 'sync test terms', true),
        ]);

        $this->assertEquals(new MappingAutoResolveResult(false, [MappingKey::DefaultTerms]), $this->useCase()->execute());
        $this->assertSame(['default_terms' => ['2', 'Sync Test Terms', self::REALM]], $this->recorded);
    }

    #[Test]
    public function a_refused_create_leaves_its_key_and_the_rest_still_resolve(): void
    {
        $this->unresolved([MappingKey::Department, MappingKey::SalesClass]);
        $this->adapter->shouldReceive('candidates')->andReturn([]);
        $this->adapter->shouldReceive('create')->andReturnUsing(
            static fn(ApiKeyToken $token, MappingEntity $entity, string $name): MappingCandidateDTO => $entity === MappingEntity::Department
                ? throw self::fault()
                : new MappingCandidateDTO('7', $name, true),
        );

        $this->assertEquals(
            new MappingAutoResolveResult(
                false,
                [MappingKey::SalesClass],
                [new UnmatchedMappingDTO(MappingKey::Department, 'QuickBooks refused the create: validation, code 6000')],
            ),
            $this->useCase()->execute(),
        );
        $this->assertSame(['class' => ['7', 'Sync Test Class', self::REALM]], $this->recorded);
    }

    #[Test]
    public function a_failed_read_stops_the_run_with_the_earlier_picks_kept(): void
    {
        $this->unresolved([MappingKey::DefaultTerms, MappingKey::TaxZero, MappingKey::SalesClass]);
        $this->adapter->shouldReceive('candidates')->andReturnUsing(
            static fn(ApiKeyToken $token, MappingEntity $entity): array => $entity === MappingEntity::Term
                ? [new MappingCandidateDTO('2', 'Sync Test Terms', true)]
                : throw self::fault(),
        );

        try {
            $this->useCase()->execute();
            $this->fail('The run did not stop');
        } catch (QuickBooksFaultException) {
            $this->assertSame(['default_terms'], \array_keys($this->recorded));
        }
    }

    #[Test]
    public function a_key_without_a_row_is_unmatched(): void
    {
        $this->unresolved([], [MappingKey::Department]);

        $this->assertEquals(
            new MappingAutoResolveResult(false, [], [new UnmatchedMappingDTO(MappingKey::Department, 'it has no row in quickbooks.mappings')]),
            $this->useCase()->execute(),
        );
    }

    /**
     * @param list<MappingKey> $keys
     * @param list<MappingKey> $withoutRow
     */
    private function unresolved(array $keys, array $withoutRow = []): void
    {
        $rows = \array_filter(MappingKey::cases(), static fn(MappingKey $key): bool => !\in_array($key, $withoutRow, true));
        $this->tokens->shouldReceive('accessToken')->andReturn(new ApiKeyToken('access'));
        $this->mappings->shouldReceive('unresolvedKeys')->once()->with(self::REALM)->andReturn($keys);
        $this->mappings->shouldReceive('all')->andReturn(\array_values(\array_map(
            static fn(MappingKey $key): MappingDTO => new MappingDTO($key, null, null, null),
            $rows,
        )));
        $this->mappings->shouldReceive('record')->andReturnUsing(function (MappingPickDTO $pick): void {
            $this->recorded[$pick->key->value] = [$pick->refId, $pick->refName, $pick->realmId];
        });
    }

    /**
     * @return list<MappingCandidateDTO>
     */
    private static function existing(MappingEntity $entity): array
    {
        $candidates = [];
        foreach (self::EXISTING as $key => $name) {
            if (MappingKey::from($key)->entity() === $entity) {
                $candidates[] = new MappingCandidateDTO("id {$name}", $name, true);
            }
        }

        return $candidates;
    }

    private static function fault(): QuickBooksFaultException
    {
        return new QuickBooksFaultException('QuickBooks', new QuickBooksFault(QuickBooksFaultKind::Validation, '6000', null, 'Jane Customer'));
    }

    private function useCase(bool $sandbox = true): AutoResolveMappingsUseCase
    {
        $environment = Mockery::mock(QuickBooksEnvironmentInterface::class);
        $environment->shouldReceive('isSandbox')->andReturn($sandbox);
        $environment->shouldReceive('realmId')->andReturn(self::REALM);
        $service = new MappingCandidateService($this->tokens, $this->adapter);

        return new AutoResolveMappingsUseCase(
            $this->mappings,
            $environment,
            $service,
            new MappingAutoTargetResolver($service),
            new RecordMappingPickUseCase($this->mappings, $environment, new NullLogger()),
            new NullLogger(),
        );
    }
}
