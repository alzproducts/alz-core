<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands\Dev;

use App\Application\Contracts\QuickBooks\QuickBooksMappingCandidatesInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\Contracts\QuickBooks\QuickBooksRealmInterface;
use App\Application\Enums\FixtureSku;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerMatchDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerResolutionDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\Enums\CustomerFailureKind;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\OrderSync\Enums\MappingKey;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use App\Application\QuickBooks\OrderSync\Results\DocumentLookupResult;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksEnvironment;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use App\Presentation\Console\Commands\Dev\SetupQuickBooksSandboxCommand;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\PendingCommand;
use Mockery;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use QuickBooksOnline\API\Data\IPPIntuitEntity;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\TestCase;

#[CoversClass(SetupQuickBooksSandboxCommand::class)]
final class SetupQuickBooksSandboxCommandTest extends TestCase
{
    use DatabaseTransactions;

    private const string REALM = '9130';

    private const array NAMES = [
        'tax.standard' => '20.0% S',
        'tax.zero' => '0.0% Z',
        'tax.eu_goods' => '0.0% ECG',
        'tax.eu_shipping' => '0.0% ECS',
        'deposit_account.paypal' => 'Sync Test PayPal',
        'deposit_account.card_opayo' => 'Sync Test Card Opayo',
        'deposit_account.shopwired_payments' => 'Sync Test ShopWired Payments',
        'payment_method.paypal' => 'Sync Test PP',
        'payment_method.card_opayo' => 'Sync Test Opayo',
        'payment_method.shopwired_payments' => 'Sync Test SWP',
        'department' => 'Sync Test Department',
        'class' => 'Sync Test Class',
        'default_terms' => 'Sync Test Terms',
        'custom_field.po_number' => 'P.O. Number',
        'custom_field.transaction_id' => 'Transaction ID',
    ];

    /** @var array<string, list<object>> QBO's rows by entity */
    private array $company = [];

    /** @var array<string, bool> */
    private array $preferences = ['CustomTxnNumbers' => true, 'AllowShipping' => true];

    /** @var array<int, array{string, bool}> label and on/off by slot */
    private array $slots = [1 => ['P.O. Number', true], 3 => ['Transaction ID', true]];

    private bool $preferencesStick = true;

    private ?string $faultsOn = null;

    /** @var list<array{string, array<string, mixed>}> entity and the fields sent */
    private array $added = [];

    /** @var list<string> */
    private array $queries = [];

    /** @var list<string> */
    private array $writes = [];

    private int $tokenReads = 0;

    private int $tokensForgotten = 0;

    private bool $rejectsToken = false;

    private bool $customerFails = false;

    /** @var list<ExistingDocumentDTO> */
    private array $documents = [];

    /** @var list<PostableDocumentDTO> */
    private array $posted = [];

    private ?BatchItemOutcomeResult $outcome = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('quickbooks.mappings')->update([
            'qbo_ref_id' => 'ref',
            'qbo_ref_name' => 'Name',
            'realm_id' => self::REALM,
            'resolved_at' => '2026-01-02 03:04:05',
            'updated_at' => '2026-01-02 03:04:05',
        ]);

        // intentional: resolved first, since a deferred provider loading later would drop the doubles below.
        $this->app->make(QuickBooksRealmInterface::class);
        $this->app->make(QuickBooksMappingRepositoryInterface::class);
        $this->app->make(QuickBooksMappingCandidatesInterface::class);
        $this->app->make(QuickBooksOrderPostingInterface::class);

        $this->app->instance(QuickBooksConfig::class, new QuickBooksConfig(
            QuickBooksEnvironment::Sandbox,
            'id',
            'secret',
            'https://example.test/callback',
            self::REALM,
        ));
        $this->app->forgetInstance(QuickBooksRealmInterface::class);

        $this->fakeToken();
        $this->fakeTransport();
        $this->fakePosting();

        DB::listen(function (QueryExecuted $query): void {
            if (\preg_match('/^\s*(insert|update|delete|merge|truncate|with)\b/i', $query->sql) === 1) {
                $this->writes[] = $query->sql;
            }
        });

        $this->company['Item'] = \array_map(
            static fn(string $sku): object => (object) ['Id' => "item-{$sku}", 'Name' => $sku, 'Active' => 'true'],
            FixtureSku::values(),
        );
        $this->company['Account'] = [self::entity('5', 'Sales', ['AccountType' => 'Income'])];
        $this->company['TaxCode'] = [
            self::entity('t1', '20.0% S'),
            self::entity('t2', '0.0% Z'),
            self::entity('t3', '0.0% ECG'),
            self::entity('t4', '0.0% ECS'),
        ];
    }

    #[Test]
    #[DataProvider('refusedEnvironments')]
    public function another_environment_is_refused_before_any_token_read_qbo_call_or_write(string $environment, bool $dryRun): void
    {
        $this->clearRows(...MappingKey::cases());
        $this->app['env'] = $environment;

        [$exit, $output] = $this->runSetup(['--dry-run' => $dryRun]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('only runs in a local or testing environment', $output);
        $this->assertSame(0, $this->tokenReads);
        $this->assertSame([], $this->queries);
        $this->assertSame([], $this->added);
        $this->assertSame([], $this->posted);
        $this->assertSame([], $this->writes);
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function refusedEnvironments(): iterable
    {
        yield 'production' => ['production', false];
        yield 'production dry run' => ['production', true];
        yield 'staging' => ['staging', false];
    }

    #[Test]
    public function the_local_environment_is_accepted(): void
    {
        $this->app['env'] = 'local';

        $this->assertSame(0, $this->runSetup()[0]);
    }

    #[Test]
    public function a_step_left_to_the_ui_prints_every_instruction_numbered_and_runs_no_proof(): void
    {
        $this->preferences['AllowShipping'] = false;
        $this->preferencesStick = false;
        $this->company['TaxCode'] = [self::entity('t1', '20.0% S')];
        $this->slots = [1 => ['P.O. Number', true]];
        $this->clearRows(MappingKey::TaxZero, MappingKey::TransactionIdField, MappingKey::Department);

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("  1. Account and settings > Sales > Sales form content: turn on Shipping (AllowShipping)\n", $output);
        $this->assertStringContainsString("  2. Taxes > VAT: set up the VAT code \"0.0% Z\" (tax.zero: no active match for \"0.0% Z\")\n", $output);
        $this->assertStringContainsString(
            '  3. Custom fields: add a Text sales field labelled exactly "Transaction ID", on for all sales forms (custom_field.transaction_id: no active match for "Transaction ID")',
            $output,
        );
        $this->assertStringNotContainsString('  4.', $output);
        $this->assertStringNotContainsString('PASS', $output);
        $this->assertSame([], $this->posted);
        $this->assertSame(['Preferences', 'Department'], \array_column($this->added, 0));
        $this->assertSame(['CustomTxnNumbers' => 'true', 'AllowShipping' => 'true'], $this->sentSalesForms());
        $this->assertSame(['Id', 'SalesFormsPrefs', 'SyncToken', 'sparse'], self::sortedKeys($this->added[0][1]));
        $this->assertSame([null, null, null], $this->row(MappingKey::TaxZero));
        $this->assertSame([null, null, null], $this->row(MappingKey::TransactionIdField));
        $this->assertSame(['new-Department-Sync Test Department', 'Sync Test Department', self::REALM], $this->row(MappingKey::Department));
    }

    #[Test]
    public function preferences_that_are_off_are_turned_on_and_read_back(): void
    {
        $this->preferences = ['CustomTxnNumbers' => false, 'AllowShipping' => false];

        [$exit, $output] = $this->runSetup();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('Preferences on: 2 of 2', $output);
        $this->assertSame(['Preferences'], \array_column($this->added, 0));
    }

    #[Test]
    public function a_refused_preferences_update_warns_without_intuits_message_and_becomes_instructions(): void
    {
        $this->preferences = ['CustomTxnNumbers' => false, 'AllowShipping' => false];
        $this->faultsOn = 'Preferences';

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('QuickBooks refused the Preferences update: validation, code 6000', $output);
        $this->assertStringContainsString('1. Account and settings > Sales > Sales form content: turn on Custom transaction numbers', $output);
        $this->assertStringContainsString('2. Account and settings > Sales > Sales form content: turn on Shipping', $output);
        $this->assertStringNotContainsString('Jane Customer', $output);
    }

    #[Test]
    public function missing_items_are_created_once_and_an_inactive_one_is_named(): void
    {
        unset($this->company['Item'][0], $this->company['Item'][1]);
        $this->company['Item'] = \array_values($this->company['Item']);
        $this->company['Item'][0]->Active = 'false';

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('1. Products and services: make FIXTURE-SKU-003 active', $output);
        $this->assertSame(['Item', 'Item'], \array_column($this->added, 0));
        $this->assertSame(['FIXTURE-SKU-001', 'FIXTURE-SKU-002'], \array_column(\array_column($this->added, 1), 'Name'));
        $this->assertSame(['NonInventory', 'NonInventory'], \array_column(\array_column(\array_column($this->added, 1), 'Type'), 'value'));

        $this->added = [];
        $this->runSetup();
        $this->assertSame([], $this->added);
    }

    #[Test]
    public function items_without_an_active_income_account_stop_the_run(): void
    {
        $this->company['Item'] = [];
        $this->company['Account'] = [
            self::entity('5', 'Sales', ['AccountType' => 'Income', 'Active' => 'false']),
            self::entity('6', 'Bank', ['AccountType' => 'Bank']),
        ];

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Stopped: UnexpectedApiResultException', $output);
        $this->assertSame([], $this->added);
    }

    #[Test]
    public function an_unexpected_result_stops_the_run_with_its_reason(): void
    {
        $this->company['Item'] = [];
        $this->company['Account'] = [];

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString(
            'Stopped: UnexpectedApiResultException: No active Income account for the Items',
            $output,
        );
    }

    #[Test]
    public function auto_resolve_fills_every_unresolved_key_without_a_prompt(): void
    {
        $this->clearRows(...MappingKey::cases());

        $command = $this->artisan('dev:setup-quickbooks-sandbox');
        $this->assertInstanceOf(PendingCommand::class, $command);
        $command->assertSuccessful()->run();

        $matched = [
            'tax.standard' => 't1',
            'tax.zero' => 't2',
            'tax.eu_goods' => 't3',
            'tax.eu_shipping' => 't4',
            'custom_field.po_number' => '1',
            'custom_field.transaction_id' => '3',
        ];
        foreach (MappingKey::cases() as $key) {
            $name = self::NAMES[$key->value];
            $id = $matched[$key->value] ?? "new-{$key->entity()->value}-{$name}";
            $this->assertSame([$id, $name, self::REALM], $this->row($key), $key->value);
        }

        $this->assertSame([
            ['Account', ['Name' => 'Sync Test PayPal', 'AccountType' => 'Bank']],
            ['Account', ['Name' => 'Sync Test Card Opayo', 'AccountType' => 'Bank']],
            ['Account', ['Name' => 'Sync Test ShopWired Payments', 'AccountType' => 'Bank']],
            ['PaymentMethod', ['Name' => 'Sync Test PP']],
            ['PaymentMethod', ['Name' => 'Sync Test Opayo']],
            ['PaymentMethod', ['Name' => 'Sync Test SWP']],
            ['Department', ['Name' => 'Sync Test Department']],
            ['Class', ['Name' => 'Sync Test Class']],
            ['Term', ['Name' => 'Sync Test Terms', 'DueDays' => 30]],
        ], $this->sorted($this->added));
    }

    #[Test]
    #[DataProvider('createdKeys')]
    public function auto_resolve_takes_an_existing_entity_of_the_synthetic_name(MappingKey $key): void
    {
        $this->clearRows($key);
        $name = self::NAMES[$key->value];
        $this->company[$key->entity()->value] = [self::entity('77', $name, ['AccountType' => 'Bank'])];

        $this->assertSame(0, $this->runSetup()[0]);
        $this->assertSame(['77', $name, self::REALM], $this->row($key));
        $this->assertSame([], $this->added);
    }

    #[Test]
    #[DataProvider('createdKeys')]
    public function auto_resolve_leaves_a_key_whose_match_is_inactive_or_repeated_or_refused(MappingKey $key): void
    {
        $name = self::NAMES[$key->value];
        $entity = $key->entity()->value;
        $inactive = self::entity('70', $name, ['Active' => 'false', 'AccountType' => 'Bank']);
        $active = self::entity('71', $name, ['AccountType' => 'Bank']);

        $this->assertLeft($key, "the match for \"{$name}\" is inactive", [$inactive]);
        $this->assertLeft($key, "several active matches for \"{$name}\"", [$active, self::entity('72', $name)]);
        $this->assertSame([], $this->added);

        $this->faultsOn = $entity;
        $this->assertLeft($key, 'QuickBooks refused the create: validation, code 6000', []);
    }

    /**
     * @return iterable<string, array{MappingKey}>
     */
    public static function createdKeys(): iterable
    {
        foreach (MappingKey::cases() as $key) {
            if (!\in_array($key->entity(), [MappingEntity::TaxCode, MappingEntity::CustomField], true)) {
                yield $key->value => [$key];
            }
        }
    }

    #[Test]
    #[DataProvider('taxKeys')]
    public function an_unmatched_inactive_or_repeated_tax_code_stays_null_and_is_named(MappingKey $key): void
    {
        $name = self::NAMES[$key->value];

        $this->assertLeft($key, "no active match for \"{$name}\"", [self::entity('1', \mb_strtolower($name) . ' ')]);
        $this->assertLeft($key, "the match for \"{$name}\" is inactive", [self::entity('1', $name, ['Active' => 'false'])]);
        $this->assertLeft($key, "several active matches for \"{$name}\"", [self::entity('1', $name), self::entity('2', $name)]);
        $this->assertSame([], $this->added);
    }

    /**
     * @return iterable<string, array{MappingKey}>
     */
    public static function taxKeys(): iterable
    {
        foreach (MappingKey::cases() as $key) {
            if ($key->entity() === MappingEntity::TaxCode) {
                yield $key->value => [$key];
            }
        }
    }

    #[Test]
    #[DataProvider('customFieldKeys')]
    public function a_custom_field_matches_only_when_enabled_and_labelled_exactly(MappingKey $key, string $nearMiss): void
    {
        $label = self::NAMES[$key->value];

        $this->slots = [1 => [$nearMiss, true]];
        $this->assertLeft($key, "no active match for \"{$label}\"");

        $this->slots = [1 => [$label, false]];
        $this->assertLeft($key, "the match for \"{$label}\" is inactive");

        $this->slots = [1 => [$label, true], 2 => [$label, true]];
        $this->assertLeft($key, "several active matches for \"{$label}\"");

        $this->slots = [2 => [$label, true]];
        $this->assertSame(0, $this->runSetup()[0]);
        $this->assertSame(['2', $label, self::REALM], $this->row($key));
        $this->assertSame([], $this->added);
    }

    /**
     * @return iterable<string, array{MappingKey, string}>
     */
    public static function customFieldKeys(): iterable
    {
        yield 'po number' => [MappingKey::PoNumberField, 'PO Number'];
        yield 'transaction id' => [MappingKey::TransactionIdField, 'transaction id'];
    }

    #[Test]
    public function every_other_key_is_resolved_before_a_run_with_an_unmatched_one_fails(): void
    {
        $this->clearRows(...MappingKey::cases());
        $this->company['TaxCode'] = [];
        $this->faultsOn = 'Account';

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        foreach (MappingKey::cases() as $key) {
            $left = \in_array($key->entity(), [MappingEntity::TaxCode, MappingEntity::Account], true);
            $this->assertSame($left ? null : self::NAMES[$key->value], $this->row($key)[1], $key->value);
            $this->assertSame($left, \str_contains($output, "({$key->value}: "), $key->value);
        }
        $this->assertSame([], $this->posted);
        $this->assertStringNotContainsString('Jane Customer', $output);
    }

    #[Test]
    public function a_row_of_another_realm_is_refilled_and_resolved_rows_keep_every_column(): void
    {
        DB::table('quickbooks.mappings')->where('key', 'department')->update(['realm_id' => 'realm-other']);
        $before = $this->table();

        $this->assertSame(0, $this->runSetup()[0]);

        $this->assertSame(['new-Department-Sync Test Department', 'Sync Test Department', self::REALM], $this->row(MappingKey::Department));
        $after = $this->table();
        unset($before['department'], $after['department']);
        $this->assertCount(14, $after);
        $this->assertSame($before, $after);
    }

    #[Test]
    public function a_rerun_with_every_key_resolved_creates_nothing_and_changes_no_row(): void
    {
        $this->clearRows(...MappingKey::cases());
        $this->assertSame(0, $this->runSetup()[0]);
        DB::table('quickbooks.mappings')->update(['resolved_at' => '2026-01-02 03:04:05', 'updated_at' => '2026-01-02 03:04:05']);
        $before = $this->table();
        [$this->added, $this->posted, $this->writes] = [[], [], []];

        $this->assertSame(0, $this->runSetup()[0]);

        $this->assertSame([], $this->added);
        $this->assertSame([], $this->posted);
        $this->assertSame([], $this->writes);
        $this->assertSame($before, $this->table());
    }

    #[Test]
    public function a_key_without_a_row_is_named(): void
    {
        DB::table('quickbooks.mappings')->where('key', 'department')->delete();

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('(department: it has no row in quickbooks.mappings)', $output);
        $this->assertSame([], $this->added);
    }

    #[Test]
    public function both_proofs_are_created_once_under_fixed_numbers_with_the_resolved_refs(): void
    {
        $this->clearRows(...MappingKey::cases());

        [$exit, $output] = $this->runSetup();

        $this->assertSame(0, $exit);
        foreach (['invoice', 'sales_receipt'] as $type) {
            $this->assertStringContainsString("PASS {$type}: 20% VAT on the goods line\n", $output);
            $this->assertStringContainsString("PASS {$type}: shipping line on SHIPPING_ITEM_ID\n", $output);
        }
        $this->assertSame(
            ['SYNC-PROOF-INV', 'SYNC-PROOF-SR'],
            \array_map(static fn(PostableDocumentDTO $document): string => $document->draft->core->docNumber, $this->posted),
        );

        $refs = $this->posted[1]->refs;
        $this->assertSame('customer', $refs->customerId);
        $this->assertSame('t1', $refs->taxCodeIdFor(TaxKey::Standard));
        $this->assertSame('item-FIXTURE-SKU-001', $refs->itemIdFor(Sku::fromString('FIXTURE-SKU-001')));
        $this->assertSame('new-Department-Sync Test Department', $refs->fixedRefIdFor(FixedRefKey::Department));
        $this->assertSame('new-Class-Sync Test Class', $refs->fixedRefIdFor(FixedRefKey::SalesClass));
        $this->assertSame('new-Term-Sync Test Terms', $refs->termsId);
        $this->assertSame('new-PaymentMethod-Sync Test PP', $refs->paymentMethodId);
        $this->assertSame('new-Account-Sync Test PayPal', $refs->depositAccountId);
        $this->assertSame(['1', '3'], [$refs->poNumberFieldId, $refs->transactionIdFieldId]);
    }

    #[Test]
    public function a_refused_proof_fails_with_its_kind_and_code_and_never_intuits_message(): void
    {
        $this->outcome = BatchItemOutcomeResult::faulted(self::fault());

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL invoice: 20% VAT on the goods line: create faulted, validation, code 6000', $output);
        $this->assertStringContainsString('4 proof(s) failed', $output);
        $this->assertStringNotContainsString('Jane Customer', $output);
    }

    /**
     * @param array<string, mixed> $fields
     */
    #[Test]
    #[DataProvider('failingDocuments')]
    public function a_document_without_the_behaviour_fails_its_proof(array $fields, string $failed, string $passed): void
    {
        $this->documents = [
            new ExistingDocumentDTO('SYNC-PROOF-INV', 'inv', DocType::Invoice),
            new ExistingDocumentDTO('SYNC-PROOF-SR', 'sr', DocType::SalesReceipt),
        ];
        $this->company['Invoice'] = [self::document('inv', $fields)];
        $this->company['SalesReceipt'] = [self::document('sr')];

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("FAIL invoice: {$failed}: document SYNC-PROOF-INV does not carry it", $output);
        $this->assertStringContainsString("PASS invoice: {$passed}\n", $output);
        $this->assertSame([], $this->posted);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, string, string}>
     */
    public static function failingDocuments(): iterable
    {
        $vat = '20% VAT on the goods line';
        $shipping = 'shipping line on SHIPPING_ITEM_ID';
        $goods = self::line('10.00', 'item-FIXTURE-SKU-001', 'ref');

        yield 'no shipping line' => [['Line' => $goods], $shipping, $vat];
        yield 'goods on another tax code' => [
            ['Line' => [self::line('10.00', 'item-FIXTURE-SKU-001', 'zero'), self::line('4.99', 'SHIPPING_ITEM_ID', 'ref')]], $vat, $shipping,
        ];
        yield 'no 20% tax line' => [['TxnTaxDetail' => self::tax('0', '14.99')], $vat, $shipping];
        yield '20% on the shipping alone' => [['TxnTaxDetail' => self::tax('20', '4.99')], $vat, $shipping];
        yield 'no tax detail' => [['TxnTaxDetail' => null], $vat, $shipping];
    }

    #[Test]
    public function a_refused_customer_fails_every_proof_and_creates_no_document(): void
    {
        $this->customerFails = true;

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('FAIL sales_receipt: shipping line on SHIPPING_ITEM_ID: customer ', $output);
        $this->assertStringContainsString('validation, code 6000', $output);
        $this->assertStringContainsString('4 proof(s) failed', $output);
        $this->assertStringNotContainsString('Jane Customer', $output);
        $this->assertSame([], $this->posted);
    }

    #[Test]
    public function a_refused_item_stops_the_run_and_never_prints_intuits_message(): void
    {
        $this->company['Item'] = [];
        $this->faultsOn = 'Item';

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Stopped: QuickBooks fault validation, code 6000', $output);
        $this->assertStringNotContainsString('Jane Customer', $output);
        $this->assertSame(0, $this->tokensForgotten);
    }

    #[Test]
    public function a_rejected_token_stops_the_run_and_is_forgotten(): void
    {
        $this->rejectsToken = true;

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('Stopped: AuthenticationExpiredException', $output);
        $this->assertStringContainsString('quickbooks:connect', $output);
        $this->assertSame(1, $this->tokensForgotten);
    }

    #[Test]
    public function a_dry_run_with_everything_set_up_says_so(): void
    {
        [$exit, $output] = $this->runSetup(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertSame("Dry run: everything is set up\n", $output);
    }

    #[Test]
    public function a_dry_run_reports_what_is_missing_and_changes_nothing(): void
    {
        $this->clearRows(MappingKey::Department);
        $this->writes = [];
        $this->preferences['AllowShipping'] = false;
        $this->company['Item'] = \array_slice($this->company['Item'], 1);
        $this->company['Item'][0]->Active = 'false';

        [$exit, $output] = $this->runSetup(['--dry-run' => true]);

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('preference AllowShipping is off', $output);
        $this->assertStringContainsString('Item FIXTURE-SKU-001 is missing', $output);
        $this->assertStringContainsString('Item FIXTURE-SKU-002 is inactive', $output);
        $this->assertStringContainsString('department: unresolved', $output);
        $this->assertSame([], $this->added);
        $this->assertSame([], $this->posted);
        $this->assertSame([], $this->writes);
    }

    private function fakeToken(): void
    {
        $tokens = Mockery::mock(QuickBooksTokenService::class);
        $tokens->shouldReceive('accessToken')->andReturnUsing(function (): ApiKeyToken {
            $this->tokenReads++;

            return new ApiKeyToken('access');
        });
        $tokens->shouldReceive('forgetAccessToken')->andReturnUsing(function (): void {
            $this->tokensForgotten++;
        });
        $this->app->instance(QuickBooksTokenService::class, $tokens);
    }

    private function fakeTransport(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('query')->andReturnUsing(function (ApiKeyToken $token, string $query): array {
            $this->queries[] = $query;
            if ($this->rejectsToken) {
                throw new AuthenticationExpiredException('QuickBooks');
            }

            return $this->answer($query);
        });
        $transport->shouldReceive('add')->andReturnUsing(
            fn(ApiKeyToken $token, IPPIntuitEntity $entity): IPPIntuitEntity => $this->add($entity),
        );
        $this->app->instance(QuickBooksTransport::class, $transport);
    }

    private function fakePosting(): void
    {
        $posting = Mockery::mock(QuickBooksOrderPostingInterface::class);
        $posting->shouldReceive('findItems')->andReturnUsing(
            static fn(ApiKeyToken $token, array $skus): ItemLookupResult => ItemLookupResult::fromMatches(
                $skus,
                \array_map(static fn(Sku $sku): array => ['name' => $sku->value, 'id' => "item-{$sku->value}"], $skus),
            ),
        );
        $posting->shouldReceive('findDocuments')->andReturnUsing(fn(): DocumentLookupResult => new DocumentLookupResult($this->documents));
        $posting->shouldReceive('findOrCreateCustomers')->andReturnUsing(
            fn(ApiKeyToken $token, array $drafts): CustomerResolutionResult => CustomerResolutionResult::of(\array_map(
                fn(CustomerDraftDTO $draft): CustomerResolutionDTO => $this->customerFails
                    ? CustomerResolutionDTO::failed($draft->displayName, CustomerFailureKind::cases()[0], self::fault())
                    : CustomerResolutionDTO::matched($draft->displayName, new CustomerMatchDTO('customer', $draft->displayName, null, null)),
                $drafts,
            )),
        );
        $posting->shouldReceive('createDocuments')->andReturnUsing(
            fn(ApiKeyToken $token, array $documents): array => \array_map($this->postDocument(...), $documents),
        );
        $this->app->instance(QuickBooksOrderPostingInterface::class, $posting);
    }

    private function postDocument(PostableDocumentDTO $document): BatchItemOutcomeResult
    {
        $this->posted[] = $document;
        if ($this->outcome !== null) {
            return $this->outcome;
        }

        $type = $document->draft instanceof SalesReceiptDraftDTO ? DocType::SalesReceipt : DocType::Invoice;
        $entity = $type === DocType::SalesReceipt ? 'SalesReceipt' : 'Invoice';
        $this->documents[] = new ExistingDocumentDTO($document->draft->core->docNumber, $type->value, $type);
        $this->company[$entity] = [self::document($type->value, [
            'Line' => [
                self::line('10.00', 'item-FIXTURE-SKU-001', (string) $document->refs->taxCodeIdFor(TaxKey::Standard)),
                self::line('4.99', 'SHIPPING_ITEM_ID', 'any'),
            ],
        ])];

        return BatchItemOutcomeResult::created($type->value);
    }

    /**
     * @return list<object>
     */
    private function answer(string $query): array
    {
        $this->assertSame(1, \preg_match('/ FROM (\w+)/', $query, $match));
        if ($match[1] === 'Preferences') {
            return [$this->preferencesRow()];
        }

        $rows = $this->company[$match[1]] ?? [];

        // intentional: QBO omits inactive rows unless a query asks for them.
        return \str_contains($query, 'Active IN (true, false)')
            ? $rows
            : \array_values(\array_filter($rows, static fn(object $row): bool => ($row->Active ?? 'true') === 'true'));
    }

    private function preferencesRow(): object
    {
        $entries = [];
        foreach ($this->slots as $slot => [$label, $on]) {
            $entries[] = (object) ['Name' => "SalesFormsPrefs.UseSalesCustom{$slot}", 'BooleanValue' => $on ? 'true' : 'false'];
            $entries[] = (object) ['Name' => "SalesFormsPrefs.SalesCustomName{$slot}", 'StringValue' => $label];
        }

        return (object) [
            'Id' => '1',
            'SyncToken' => '4',
            'SalesFormsPrefs' => (object) [
                ...\array_map(static fn(bool $on): string => $on ? 'true' : 'false', $this->preferences),
                'CustomField' => (object) ['CustomField' => $entries],
            ],
        ];
    }

    private function add(IPPIntuitEntity $entity): IPPIntuitEntity
    {
        $kind = \str_replace('IPP', '', \class_basename($entity));
        $fields = \array_filter(\get_object_vars($entity), static fn(mixed $value): bool => $value !== null);
        $this->added[] = [$kind, $fields];
        if ($this->faultsOn === $kind) {
            throw new QuickBooksFaultException('QuickBooks', self::fault());
        }

        if ($kind === 'Preferences') {
            $this->preferences = $this->preferencesStick ? ['CustomTxnNumbers' => true, 'AllowShipping' => true] : $this->preferences;

            return $entity;
        }

        $name = (string) $fields['Name'];
        $entity->Id = "new-{$kind}-{$name}";
        $this->company[$kind][] = self::entity($entity->Id, $name, ['AccountType' => 'Bank']);

        return $entity;
    }

    /**
     * @param list<object>|null $rows replaces the company's rows of the key's entity
     */
    private function assertLeft(MappingKey $key, string $reason, ?array $rows = null): void
    {
        $this->clearRows($key);
        if ($rows !== null) {
            $this->company[$key->entity()->value] = $rows;
        }

        [$exit, $output] = $this->runSetup();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString("({$key->value}: {$reason})", $output);
        $this->assertSame([null, null, null], $this->row($key));
    }

    /**
     * @param array<string, bool> $options
     *
     * @return array{int, string}
     */
    private function runSetup(array $options = []): array
    {
        $output = new BufferedOutput();

        return [Artisan::call('dev:setup-quickbooks-sandbox', $options, $output), $output->fetch()];
    }

    private function clearRows(MappingKey ...$keys): void
    {
        DB::table('quickbooks.mappings')
            ->whereIn('key', \array_column($keys, 'value'))
            ->update(['qbo_ref_id' => null, 'qbo_ref_name' => null, 'realm_id' => null, 'resolved_at' => null]);
        $this->writes = [];
    }

    /**
     * @return list<mixed>
     */
    private function row(MappingKey $key): array
    {
        $row = (array) DB::table('quickbooks.mappings')->where('key', $key->value)->first(['qbo_ref_id', 'qbo_ref_name', 'realm_id']);

        return \array_values($row);
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function table(): array
    {
        return DB::table('quickbooks.mappings')->orderBy('id')->get()
            ->mapWithKeys(static fn(object $row): array => [$row->key => (array) $row])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function sentSalesForms(): array
    {
        return \array_filter(\get_object_vars($this->added[0][1]['SalesFormsPrefs']), static fn(mixed $value): bool => $value !== null);
    }

    /**
     * @param array<string, mixed> $fields
     *
     * @return list<string>
     */
    private static function sortedKeys(array $fields): array
    {
        $keys = \array_keys($fields);
        \sort($keys);

        return $keys;
    }

    /**
     * @param list<array{string, array<string, mixed>}> $added
     *
     * @return list<array{string, array<string, mixed>}> in MappingKey order, each with its Name first and no Id
     */
    private function sorted(array $added): array
    {
        $order = \array_flip(\array_values(self::NAMES));
        \usort($added, static fn(array $a, array $b): int => $order[$a[1]['Name']] <=> $order[$b[1]['Name']]);

        return \array_map(static fn(array $one): array => [$one[0], \array_map(
            static fn(mixed $value): mixed => \is_object($value) ? $value->value : $value,
            ['Name' => $one[1]['Name'], ...$one[1]],
        )], $added);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function entity(string $id, string $name, array $fields = []): object
    {
        return (object) ['Id' => $id, 'Name' => $name, 'FullyQualifiedName' => $name, 'Active' => 'true', ...$fields];
    }

    /**
     * @param array<string, mixed> $fields
     */
    private static function document(string $id, array $fields = []): object
    {
        return (object) [
            'Id' => $id,
            'Line' => [self::line('10.00', 'item-FIXTURE-SKU-001', 'ref'), self::line('4.99', 'SHIPPING_ITEM_ID', 'ref')],
            'TxnTaxDetail' => self::tax('20', '14.99'),
            ...$fields,
        ];
    }

    private static function line(string $amount, string $item, string $taxCode): object
    {
        return (object) ['Amount' => $amount, 'SalesItemLineDetail' => (object) ['ItemRef' => $item, 'TaxCodeRef' => $taxCode]];
    }

    private static function tax(string $percent, string $net): object
    {
        return (object) ['TaxLine' => (object) ['TaxLineDetail' => (object) ['TaxPercent' => $percent, 'NetAmountTaxable' => $net]]];
    }

    private static function fault(): QuickBooksFault
    {
        return new QuickBooksFault(QuickBooksFaultKind::Validation, '6000', null, 'Jane Customer');
    }
}
