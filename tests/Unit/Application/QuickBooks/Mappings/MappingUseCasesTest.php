<?php

declare(strict_types=1);

namespace Tests\Unit\Application\QuickBooks\Mappings;

use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingFindingDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingPickDTO;
use App\Application\QuickBooks\Mappings\Results\MappingCheckResult;
use App\Application\QuickBooks\Mappings\Services\MappingCandidateService;
use App\Application\QuickBooks\Mappings\UseCases\CheckMappingsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\ListMappingCandidatesUseCase;
use App\Application\QuickBooks\Mappings\UseCases\ListUnresolvedMappingsUseCase;
use App\Application\QuickBooks\Mappings\UseCases\RecordMappingPickUseCase;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\OrderSync\Enums\MappingFindingKind;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

#[CoversClass(ListUnresolvedMappingsUseCase::class)]
#[CoversClass(ListMappingCandidatesUseCase::class)]
#[CoversClass(RecordMappingPickUseCase::class)]
#[CoversClass(CheckMappingsUseCase::class)]
#[CoversClass(MappingFindingDTO::class)]
#[CoversClass(MappingCheckResult::class)]
final class MappingUseCasesTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private const string REALM = '9130';

    private MockInterface&QuickBooksMappingRepositoryInterface $mappings;

    private MockInterface&QuickBooksMappingCandidatesInterface $adapter;

    private MockInterface&QuickBooksRealmInterface $realm;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->mappings = Mockery::mock(QuickBooksMappingRepositoryInterface::class);
        $this->adapter = Mockery::mock(QuickBooksMappingCandidatesInterface::class);
        $this->realm = Mockery::mock(QuickBooksRealmInterface::class);
        $this->realm->shouldReceive('realmId')->andReturn(self::REALM);
    }

    #[Test]
    public function unresolved_keys_are_read_for_the_configured_realm(): void
    {
        $this->mappings->shouldReceive('unresolvedKeys')->once()->with(self::REALM)->andReturn([MappingKey::Department]);

        $useCase = new ListUnresolvedMappingsUseCase($this->mappings, $this->realm, new NullLogger());

        $this->assertSame([MappingKey::Department], $useCase->execute());
    }

    #[Test]
    public function only_active_candidates_of_the_keys_entity_are_listed(): void
    {
        $active = new MappingCandidateDTO('2', 'Web', true);
        $this->adapter->shouldReceive('candidates')
            ->once()
            ->with(Mockery::type(ApiKeyToken::class), MappingKey::SalesClass->entity())
            ->andReturn([new MappingCandidateDTO('1', 'Old', false), $active]);

        $useCase = new ListMappingCandidatesUseCase($this->candidates(), new NullLogger());

        $this->assertSame([$active], $useCase->execute(MappingKey::SalesClass));
    }

    /**
     * @return array<string, array{MappingDTO|null}>
     */
    public static function differingRows(): array
    {
        return [
            'other ref' => [new MappingDTO(MappingKey::Department, '1', 'Sales', self::REALM)],
            'other name' => [new MappingDTO(MappingKey::Department, '42', 'Old', self::REALM)],
            'other realm' => [new MappingDTO(MappingKey::Department, '42', 'Sales', 'other')],
            'other key' => [new MappingDTO(MappingKey::SalesClass, '42', 'Sales', self::REALM)],
            'no row' => [null],
        ];
    }

    #[Test]
    #[DataProvider('differingRows')]
    public function a_pick_that_differs_from_the_row_is_recorded(?MappingDTO $row): void
    {
        $this->mappings->shouldReceive('all')->once()->andReturn($row === null ? [] : [$row]);
        $this->mappings->shouldReceive('record')
            ->once()
            ->with(Mockery::on(static fn(MappingPickDTO $pick): bool => [$pick->key, $pick->refId, $pick->refName, $pick->realmId]
                === [MappingKey::Department, '42', 'Sales', self::REALM]));

        $this->assertTrue($this->record()->execute(MappingKey::Department, new MappingCandidateDTO('42', 'Sales', true)));
    }

    #[Test]
    public function a_pick_equal_to_the_row_is_not_recorded(): void
    {
        $this->mappings->shouldReceive('all')->once()->andReturn([
            new MappingDTO(MappingKey::SalesClass, '1', 'Web', self::REALM),
            new MappingDTO(MappingKey::Department, '42', 'Sales', self::REALM),
        ]);
        $this->mappings->shouldNotReceive('record');

        $this->assertFalse($this->record()->execute(MappingKey::Department, new MappingCandidateDTO('42', 'Sales', true)));
    }

    #[Test]
    public function the_check_classifies_every_key_and_lists_every_finding_that_applies(): void
    {
        $rows = self::rows();
        $rows['tax.zero'] = new MappingDTO(MappingKey::TaxZero, null, null, null);
        $rows['class'] = new MappingDTO(MappingKey::SalesClass, 'ref', 'Web', 'other');
        $rows['department'] = new MappingDTO(MappingKey::Department, 'drifted', 'Sales', self::REALM);
        $rows['custom_field.po_number'] = new MappingDTO(MappingKey::PoNumberField, 'unlabelled', 'P.O. Number', self::REALM);
        $rows['custom_field.transaction_id'] = new MappingDTO(MappingKey::TransactionIdField, 'gone', 'Transaction ID', self::REALM);
        unset($rows['tax.standard']);
        $this->mappings->shouldReceive('all')->andReturn(\array_values($rows));
        $this->mappings->shouldReceive('unresolvedKeys')->with(self::REALM)->andReturn([MappingKey::TaxZero, MappingKey::SalesClass]);
        $this->mappings->shouldNotReceive('record');
        $this->adapter->shouldReceive('candidates')->andReturn([
            new MappingCandidateDTO('ref', 'Name', true),
            new MappingCandidateDTO('drifted', 'Renamed', false),
            new MappingCandidateDTO('unlabelled', '', true),
        ]);

        $findings = $this->check()->execute()->findings;

        $this->assertEqualsCanonicalizing([
            ['tax.standard', MappingFindingKind::Unresolved->value, null, null],
            ['tax.zero', MappingFindingKind::Unresolved->value, null, null],
            ['class', MappingFindingKind::OtherRealm->value, 'Web', null],
            ['department', MappingFindingKind::NameDrift->value, 'Sales', 'Renamed'],
            ['department', MappingFindingKind::Inactive->value, 'Sales', 'Renamed'],
            ['custom_field.transaction_id', MappingFindingKind::NotFound->value, 'Transaction ID', null],
        ], \array_map(
            static fn(MappingFindingDTO $finding): array => [$finding->key->value, $finding->kind->value, $finding->storedName, $finding->currentName],
            $findings,
        ));
    }

    #[Test]
    public function a_clean_check_reads_each_entity_once_and_finds_nothing(): void
    {
        $this->mappings->shouldReceive('all')->andReturn(\array_values(self::rows()));
        $this->mappings->shouldReceive('unresolvedKeys')->andReturn([]);
        $this->adapter->shouldReceive('candidates')
            ->times(\count(MappingEntity::cases()))
            ->andReturn([new MappingCandidateDTO('ref', 'Name', true)]);

        $this->assertSame([], $this->check()->execute()->findings);
    }

    /**
     * @return array<string, MappingDTO>
     */
    private static function rows(): array
    {
        $rows = [];
        foreach (MappingKey::cases() as $key) {
            $rows[$key->value] = new MappingDTO($key, 'ref', 'Name', self::REALM);
        }

        return $rows;
    }

    private function record(): RecordMappingPickUseCase
    {
        return new RecordMappingPickUseCase($this->mappings, $this->realm, new NullLogger());
    }

    private function check(): CheckMappingsUseCase
    {
        return new CheckMappingsUseCase($this->mappings, $this->candidates(), $this->realm, new NullLogger());
    }

    private function candidates(): MappingCandidateService
    {
        $tokens = Mockery::mock(QuickBooksTokenService::class);
        $tokens->shouldReceive('accessToken')->andReturn(new ApiKeyToken('access'));

        return new MappingCandidateService($tokens, $this->adapter);
    }
}
