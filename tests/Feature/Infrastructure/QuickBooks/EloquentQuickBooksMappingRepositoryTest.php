<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingDTO;
use App\Application\QuickBooks\Mappings\DTOs\MappingPickDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Domain\Exceptions\Api\RecordNotFoundException;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Infrastructure\QuickBooks\EloquentQuickBooksMappingRepository;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(EloquentQuickBooksMappingRepository::class)]
#[CoversClass(MappingKey::class)]
final class EloquentQuickBooksMappingRepositoryTest extends TestCase
{
    use DatabaseTransactions;

    private const string REALM = 'realm-mapping-test';

    private const string OTHER_REALM = 'realm-mapping-other';

    private QuickBooksMappingRepositoryInterface $repository;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = $this->app->make(QuickBooksMappingRepositoryInterface::class);
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref', 'qbo_ref_name' => 'Name', 'realm_id' => self::REALM]);
    }

    #[Test]
    public function the_enums_equal_the_seeded_rows(): void
    {
        $seeded = DB::table('quickbooks.mappings')->pluck('qbo_entity', 'key')->all();
        $cases = [];
        foreach (MappingKey::cases() as $key) {
            $cases[$key->value] = $key->entity()->value;
        }

        $this->assertEqualsCanonicalizing(\array_keys($seeded), \array_keys($cases));
        \ksort($seeded);
        \ksort($cases);
        $this->assertSame($seeded, $cases);
        $this->assertEqualsCanonicalizing(
            \array_map(static fn(MappingEntity $entity): string => $entity->value, MappingEntity::cases()),
            \array_values(\array_unique($seeded)),
        );
    }

    #[Test]
    public function null_ref_other_realm_and_null_realm_rows_are_unresolved_without_a_qbo_call(): void
    {
        Http::fake();
        $this->setRow(MappingKey::TaxZero, ['qbo_ref_id' => null]);
        $this->setRow(MappingKey::Department, ['realm_id' => self::OTHER_REALM]);
        $this->setRow(MappingKey::PoNumberField, ['realm_id' => null]);

        $this->assertEqualsCanonicalizing(
            [MappingKey::TaxZero, MappingKey::Department, MappingKey::PoNumberField],
            $this->repository->unresolvedKeys(self::REALM),
        );
        Http::assertNothingSent();
    }

    #[Test]
    public function every_key_is_unresolved_for_a_realm_with_no_refs(): void
    {
        $this->assertCount(\count(MappingKey::cases()), $this->repository->unresolvedKeys('realm-mapping-none'));
        $this->assertSame([], $this->repository->unresolvedKeys(self::REALM));
    }

    #[Test]
    public function resolved_refs_hold_the_realms_ids_and_nothing_for_another(): void
    {
        $this->setRow(MappingKey::PoNumberField, ['qbo_ref_id' => '1']);
        $this->setRow(MappingKey::TransactionIdField, ['qbo_ref_id' => '3']);
        $this->setRow(MappingKey::TaxZero, ['qbo_ref_id' => '9', 'realm_id' => null]);

        $refs = $this->repository->resolvedRefs(self::REALM);
        $this->assertSame(['1', '3'], [$refs->poNumberField(), $refs->transactionIdField()]);
        $this->assertNull($refs->tax(TaxKey::Zero));

        $other = $this->repository->resolvedRefs(self::OTHER_REALM);
        $this->assertNull($other->poNumberField());
        $this->assertNull($other->transactionIdField());
    }

    #[Test]
    public function all_returns_every_row_with_its_snapshot(): void
    {
        $this->setRow(MappingKey::TaxZero, ['qbo_ref_id' => null, 'qbo_ref_name' => null, 'realm_id' => null]);
        $this->setRow(MappingKey::SalesClass, ['qbo_ref_id' => '5', 'qbo_ref_name' => 'Web', 'realm_id' => self::OTHER_REALM]);

        $rows = \array_column(
            \array_map(static fn(MappingDTO $row): array => ['key' => $row->key->value, 'row' => $row], $this->repository->all()),
            'row',
            'key',
        );

        $this->assertCount(\count(MappingKey::cases()), $rows);
        $this->assertEquals(new MappingDTO(MappingKey::TaxZero, null, null, null), $rows['tax.zero']);
        $this->assertEquals(new MappingDTO(MappingKey::SalesClass, '5', 'Web', self::OTHER_REALM), $rows['class']);
    }

    #[Test]
    public function record_overwrites_the_row_and_stamps_it(): void
    {
        $this->setRow(MappingKey::Department, ['realm_id' => self::OTHER_REALM, 'resolved_at' => null, 'updated_at' => '2020-01-01 00:00:00+00']);

        $this->repository->record(new MappingPickDTO(MappingKey::Department, '42', 'Sales', self::REALM));

        $row = DB::table('quickbooks.mappings')
            ->where('key', 'department')
            ->selectRaw('qbo_ref_id, qbo_ref_name, realm_id, resolved_at = now() AS resolved_now, updated_at = now() AS updated_now')
            ->first();
        $this->assertEquals(
            (object) ['qbo_ref_id' => '42', 'qbo_ref_name' => 'Sales', 'realm_id' => self::REALM, 'resolved_now' => true, 'updated_now' => true],
            $row,
        );
    }

    #[Test]
    public function record_throws_for_a_key_with_no_row_and_inserts_nothing(): void
    {
        DB::table('quickbooks.mappings')->where('key', 'department')->delete();

        try {
            $this->repository->record(new MappingPickDTO(MappingKey::Department, '42', 'Sales', self::REALM));
            $this->fail('Expected RecordNotFoundException');
        } catch (RecordNotFoundException $exception) {
            $this->assertSame('department', $exception->resourceId);
        }

        $this->assertFalse(DB::table('quickbooks.mappings')->where('key', 'department')->exists());
    }

    /**
     * @param array<string, string|null> $values
     */
    private function setRow(MappingKey $key, array $values): void
    {
        DB::table('quickbooks.mappings')->where('key', $key->value)->update($values);
    }
}
