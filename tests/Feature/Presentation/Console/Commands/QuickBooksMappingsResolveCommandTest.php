<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\QuickBooks\QuickBooksEnvironmentInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksEnvironment;
use App\Presentation\Console\Commands\QuickBooksMappingsResolveCommand;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversClass(QuickBooksMappingsResolveCommand::class)]
final class QuickBooksMappingsResolveCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const string REALM = '9130';

    private MockInterface&QuickBooksMappingCandidatesInterface $adapter;

    /** @var list<string> */
    private array $updates = [];

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref', 'qbo_ref_name' => 'Name', 'realm_id' => self::REALM]);

        // intentional: resolved first, since a deferred provider loading later would drop the doubles below.
        $this->app->make(QuickBooksEnvironmentInterface::class);
        $this->app->make(QuickBooksMappingRepositoryInterface::class);
        $this->app->make(QuickBooksMappingCandidatesInterface::class);

        $this->app->instance(QuickBooksConfig::class, new QuickBooksConfig(
            QuickBooksEnvironment::Production,
            'id',
            'secret',
            'https://example.test/callback',
            self::REALM,
        ));
        $this->app->forgetInstance(QuickBooksEnvironmentInterface::class);

        $tokens = Mockery::mock(QuickBooksTokenService::class);
        $tokens->shouldReceive('accessToken')->andReturn(new ApiKeyToken('access'));
        $this->app->instance(QuickBooksTokenService::class, $tokens);

        $this->adapter = Mockery::mock(QuickBooksMappingCandidatesInterface::class);
        $this->adapter->shouldNotReceive('create');
        $this->app->instance(QuickBooksMappingCandidatesInterface::class, $this->adapter);

        DB::listen(function (QueryExecuted $query): void {
            if (\str_starts_with(\mb_strtolower($query->sql), 'update') && \str_contains($query->sql, 'mappings')) {
                $this->updates[] = $query->sql;
            }
        });
    }

    #[Test]
    public function interactive_mode_stores_both_definition_ids_with_the_production_realm_and_a_rerun_writes_nothing(): void
    {
        $this->clearRow(MappingKey::PoNumberField);
        DB::table('quickbooks.mappings')->where('key', 'custom_field.transaction_id')->update(['realm_id' => 'realm-other']);
        $this->adapterReturns([
            new MappingCandidateDTO('1', 'P.O. Number', true),
            new MappingCandidateDTO('2', '', true),
            new MappingCandidateDTO('3', 'Transaction ID', true),
            new MappingCandidateDTO('4', 'Old', false),
        ]);

        $options = ['Skip', 'P.O. Number [id 1]', '(unnamed) [id 2]', 'Transaction ID [id 3]'];
        $this->resolve()
            ->expectsChoice(self::question(MappingKey::PoNumberField), 'P.O. Number [id 1]', $options, true)
            ->expectsChoice(self::question(MappingKey::TransactionIdField), 'Transaction ID [id 3]', $options, true)
            ->assertSuccessful()
            ->run();

        $this->assertSame(['1', 'P.O. Number', self::REALM], $this->row(MappingKey::PoNumberField));
        $this->assertSame(['3', 'Transaction ID', self::REALM], $this->row(MappingKey::TransactionIdField));

        $refs = $this->app->make(QuickBooksMappingRepositoryInterface::class)->resolvedRefs(self::REALM);
        $this->assertSame(['1', '3'], [$refs->poNumberField(), $refs->transactionIdField()]);

        $this->updates = [];
        $this->resolve()->expectsOutputToContain('No unresolved mapping is left')->assertSuccessful()->run();
        $this->assertSame([], $this->updates);
    }

    #[Test]
    public function interactive_mode_lists_skips_and_keys_without_an_active_candidate_and_still_exits_zero(): void
    {
        $this->clearRow(MappingKey::Department);
        $this->clearRow(MappingKey::SalesClass);
        $this->updates = [];
        $this->adapter->shouldReceive('candidates')->andReturnUsing(
            static fn(ApiKeyToken $token, MappingEntity $entity): array => $entity === MappingKey::Department->entity()
                ? [new MappingCandidateDTO('1', 'Off', false)]
                : [new MappingCandidateDTO('2', 'Web', true)],
        );

        $this->resolve()
            ->expectsQuestion(self::question(MappingKey::SalesClass), 'Skip')
            ->expectsOutputToContain('department: no active candidate')
            ->expectsOutputToContain('class: skipped')
            ->assertSuccessful()
            ->run();

        $this->assertSame([], $this->updates);
    }

    #[Test]
    public function the_key_option_prompts_for_a_resolved_key_and_an_equal_pick_writes_nothing(): void
    {
        $this->adapterReturns([new MappingCandidateDTO('ref', 'Name', true), new MappingCandidateDTO('9', 'Other', true)]);

        $this->resolve(['--key' => 'department'])
            ->expectsQuestion(self::question(MappingKey::Department), 'Name [id ref]')
            ->expectsOutputToContain('already holds')
            ->assertSuccessful()
            ->run();
        $this->assertSame([], $this->updates);

        $this->resolve(['--key' => 'department'])
            ->expectsQuestion(self::question(MappingKey::Department), 'Other [id 9]')
            ->assertSuccessful()
            ->run();
        $this->assertSame(['9', 'Other', self::REALM], $this->row(MappingKey::Department));
        $this->assertSame(['ref', 'Name', self::REALM], $this->row(MappingKey::SalesClass));
    }

    #[Test]
    public function the_key_option_fails_on_a_skip(): void
    {
        $this->adapterReturns([new MappingCandidateDTO('9', 'Other', true)]);

        $this->resolve(['--key' => 'department'])
            ->expectsQuestion(self::question(MappingKey::Department), 'Skip')
            ->expectsOutputToContain('department: skipped')
            ->assertFailed()
            ->run();
        $this->assertSame([], $this->updates);
    }

    #[Test]
    public function the_key_option_fails_without_an_active_candidate(): void
    {
        $this->adapterReturns([new MappingCandidateDTO('9', 'Other', false)]);

        $this->resolve(['--key' => 'department'])->expectsOutputToContain('no active candidate')->assertFailed()->run();
        $this->assertSame([], $this->updates);
    }

    #[Test]
    public function an_unknown_key_fails_with_the_valid_keys_and_no_read(): void
    {
        $this->adapter->shouldNotReceive('candidates');

        $this->resolve(['--key' => 'nope'])->expectsOutputToContain('custom_field.po_number')->assertFailed()->run();
    }

    #[Test]
    public function two_options_fail_before_any_read(): void
    {
        $this->adapter->shouldNotReceive('candidates');
        $reads = 0;
        DB::listen(static function () use (&$reads): void {
            $reads++;
        });

        $this->resolve(['--key' => 'department', '--check' => true])->assertFailed()->run();

        $this->assertSame(0, $reads);
    }

    /**
     * @return array<string, array{array<string, string|bool>}>
     */
    public static function promptingModes(): array
    {
        return ['bulk' => [[]], 'single key' => [['--key' => 'department']]];
    }

    /**
     * @param array<string, string|bool> $options
     */
    #[Test]
    #[DataProvider('promptingModes')]
    public function a_run_that_cannot_prompt_fails_before_any_write(array $options): void
    {
        $this->clearRow(MappingKey::Department);
        $this->updates = [];
        $this->adapterReturns([new MappingCandidateDTO('9', 'Other', true)]);

        $this->resolve([...$options, '--no-interaction' => true])->expectsOutputToContain('cannot prompt')->assertFailed()->run();

        $this->assertSame([], $this->updates);
    }

    #[Test]
    public function a_bulk_run_that_needs_no_prompt_succeeds_without_interaction(): void
    {
        $this->clearRow(MappingKey::Department);
        $this->adapterReturns([]);

        $this->resolve(['--no-interaction' => true])->expectsOutputToContain('department: no active candidate')->assertSuccessful()->run();
    }

    #[Test]
    public function the_key_option_fails_for_a_deleted_row(): void
    {
        DB::table('quickbooks.mappings')->where('key', 'department')->delete();
        $this->adapterReturns([new MappingCandidateDTO('9', 'Other', true)]);

        $this->resolve(['--key' => 'department'])
            ->expectsQuestion(self::question(MappingKey::Department), 'Other [id 9]')
            ->expectsOutputToContain('RecordNotFoundException')
            ->assertFailed()
            ->run();
        $this->assertFalse(DB::table('quickbooks.mappings')->where('key', 'department')->exists());
    }

    #[Test]
    public function a_clean_check_exits_zero_and_writes_nothing(): void
    {
        $this->adapterReturns([new MappingCandidateDTO('ref', 'Name', true)]);

        $this->resolve(['--check' => true])->assertSuccessful()->run();
        $this->assertSame([], $this->updates);
    }

    #[Test]
    public function a_check_reports_findings_a_deleted_row_included_and_fails(): void
    {
        DB::table('quickbooks.mappings')->where('key', 'department')->delete();
        $this->adapterReturns([new MappingCandidateDTO('ref', 'Renamed', true)]);

        $this->resolve(['--check' => true])
            ->expectsOutputToContain('name drift')
            ->expectsOutputToContain('unresolved')
            ->assertFailed()
            ->run();
        $this->assertSame([], $this->updates);
    }

    #[Test]
    public function a_fault_prints_its_kind_and_code_and_never_intuits_message(): void
    {
        $kind = QuickBooksFaultKind::cases()[0];
        $this->adapter->shouldReceive('candidates')->andThrow(
            new QuickBooksFaultException('QuickBooks', new QuickBooksFault($kind, '6000', null, 'Jane Customer')),
        );

        $this->resolve(['--check' => true])
            ->expectsOutputToContain("QuickBooks fault {$kind->value}, code 6000")
            ->doesntExpectOutputToContain('Jane Customer')
            ->assertFailed()
            ->run();
    }

    /**
     * @param array<string, string|bool> $options
     */
    private function resolve(array $options = []): PendingCommand
    {
        $command = $this->artisan('quickbooks:mappings:resolve', $options);
        $this->assertInstanceOf(PendingCommand::class, $command);

        return $command;
    }

    /**
     * @param list<MappingCandidateDTO> $candidates
     */
    private function adapterReturns(array $candidates): void
    {
        $this->adapter->shouldReceive('candidates')->andReturn($candidates);
    }

    private static function question(MappingKey $key): string
    {
        return "Pick the QuickBooks {$key->entity()->value} for {$key->value}";
    }

    private function clearRow(MappingKey $key): void
    {
        DB::table('quickbooks.mappings')->where('key', $key->value)->update(['qbo_ref_id' => null, 'qbo_ref_name' => null, 'realm_id' => null]);
    }

    /**
     * @return list<mixed>
     */
    private function row(MappingKey $key): array
    {
        $row = (array) DB::table('quickbooks.mappings')->where('key', $key->value)->first(['qbo_ref_id', 'qbo_ref_name', 'realm_id']);

        return \array_values($row);
    }
}
