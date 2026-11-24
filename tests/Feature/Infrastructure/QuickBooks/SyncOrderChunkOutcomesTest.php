<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use App\Application\Contracts\QuickBooks\OrderSyncRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksMappingRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncChunkDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncPostedDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderSyncResultDTO;
use App\Application\QuickBooks\OrderSync\Enums\CustomerFailureKind;
use App\Application\QuickBooks\OrderSync\Enums\OrderSyncTrigger;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\UseCases\SyncOrderChunkUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Exceptions\Infrastructure\DatabaseOperationFailedException;
use App\Domain\Exceptions\Infrastructure\LockAcquisitionException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\Jobs\QuickBooks\SyncQuickBooksOrderChunkJob;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Error;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Routing\Route;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Testing\TestResponse;
use Override;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Fixtures\QuickBooks\FakeQuickBooksOrderPosting;
use Tests\Fixtures\QuickBooks\InterceptedMappingRepository;
use Tests\Fixtures\QuickBooks\InterceptedOrderSyncRepository;
use Tests\Fixtures\QuickBooks\UnforgettableTokenCache;
use Tests\TestCase;
use Throwable;

#[CoversNothing]
final class SyncOrderChunkOutcomesTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;

    private const string REALM = '9130000000000001';

    private const int REFERENCE = 890000001;

    private const int OTHER_REFERENCE = 890000002;

    private const array LIVE = [
        'status' => 'processing',
        'claim_token' => '00000000-0000-4000-8000-000000000001',
        'lease_expires_at' => '2099-01-01 00:00:00+00',
    ];

    private const array LAPSED = [
        'status' => 'processing',
        'claim_token' => '00000000-0000-4000-8000-000000000001',
        'lease_expires_at' => '2026-01-01 00:00:00+00',
    ];

    private FakeQuickBooksOrderPosting $posting;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        DB::table('quickbooks.order_syncs')->delete();
        DB::table('quickbooks.mappings')->update(['qbo_ref_id' => 'ref-1', 'qbo_ref_name' => 'Ref 1', 'realm_id' => self::REALM]);
        $this->posting = new FakeQuickBooksOrderPosting();
        $this->app->instance(QuickBooksOrderPostingInterface::class, $this->posting);
        $this->cacheToken();
    }

    /**
     * @return iterable<string, array{bool}>
     */
    public static function forceRetries(): iterable
    {
        yield 'without force_retry' => [false];
        yield 'with force_retry' => [true];
    }

    #[Test]
    #[DataProvider('forceRetries')]
    public function a_synced_row_is_already_synced(bool $forceRetry): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->insertSync(['status' => 'synced', 'doc_type' => 'invoice', 'qbo_doc_number' => 'DOC-1', 'qbo_txn_id' => 'txn-1']);

        $this->sync(['references' => [self::REFERENCE], 'force_retry' => $forceRetry])
            ->assertOk()
            ->assertJsonPath('counts.already_synced', 1)
            ->assertJsonPath('results', [[
                'reference' => self::REFERENCE,
                'outcome' => 'already_synced',
                'doc_type' => 'invoice',
                'qbo_doc_number' => 'DOC-1',
                'qbo_txn_id' => 'txn-1',
                'reason' => null,
            ]]);
        self::assertSame([], $this->posting->calls);
    }

    #[Test]
    public function a_failed_row_is_skipped_without_force_retry(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'item_missing']);
        $before = DB::table('quickbooks.order_syncs')->get()->toArray();

        $this->sync(['references' => [self::REFERENCE]])
            ->assertOk()
            ->assertJsonPath('results.0.outcome', 'skipped')
            ->assertJsonPath('results.0.reason.code', 'previously_failed');
        self::assertEquals($before, DB::table('quickbooks.order_syncs')->get()->toArray());
        self::assertSame([], $this->posting->calls);
    }

    #[Test]
    public function results_keep_the_request_order(): void
    {

        $this->sync(['references' => [self::OTHER_REFERENCE, self::REFERENCE]])
            ->assertOk()
            ->assertJsonPath('results.0.reference', self::OTHER_REFERENCE)
            ->assertJsonPath('results.1.reference', self::REFERENCE);
    }

    #[Test]
    public function ten_references_over_every_outcome_are_counted_with_their_fields(): void
    {
        $this->posting->missingSkus = ['SKU-GONE'];
        $references = \range(self::REFERENCE, self::REFERENCE + 9);
        [$new, $synced, $cancelled, $unknown, $failed, $noItem, $leased, $lapsed, $pending, $new2] = $references;
        foreach ([$new, $synced, $failed, $leased, $lapsed, $pending, $new2] as $reference) {
            $this->insertOrder($reference);
        }
        $this->insertOrder($cancelled, state: ['lifecycle_status' => 'cancelled']);
        $this->insertOrder($noItem, sku: 'SKU-GONE');
        $this->insertSync(['status' => 'synced', 'doc_type' => 'invoice', 'qbo_doc_number' => 'DOC-1', 'qbo_txn_id' => 'txn-1'], $synced);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'item_missing'], $failed);
        $this->insertSync(self::LIVE, $leased);
        $this->insertSync(self::LAPSED, $lapsed);
        $this->insertSync(['status' => 'pending'], $pending);

        $response = $this->sync(['references' => $references])
            ->assertOk()
            ->assertJsonPath('total', 10)
            ->assertJsonPath('counts', ['synced' => 4, 'already_synced' => 1, 'skipped' => 3, 'failed' => 1, 'not_attempted' => 1]);

        self::assertSame([
            self::expected($new, 'synced', 'sales_receipt', "A{$new}", "txn-A{$new}"),
            self::expected($synced, 'already_synced', 'invoice', 'DOC-1', 'txn-1'),
            self::expected($cancelled, 'skipped', reason: ['code' => 'cancelled', 'retryable' => false]),
            self::expected($unknown, 'skipped', reason: ['code' => 'order_not_found', 'retryable' => false]),
            self::expected($failed, 'skipped', reason: ['code' => 'previously_failed', 'retryable' => true]),
            self::expected($noItem, 'failed', 'sales_receipt', reason: ['code' => 'item_missing', 'retryable' => true]),
            self::expected($leased, 'not_attempted', reason: ['code' => 'in_progress', 'retryable' => true]),
            self::expected($lapsed, 'synced', 'sales_receipt', "A{$lapsed}", "txn-A{$lapsed}"),
            self::expected($pending, 'synced', 'sales_receipt', "A{$pending}", "txn-A{$pending}"),
            self::expected($new2, 'synced', 'sales_receipt', "A{$new2}", "txn-A{$new2}"),
        ], self::withoutMessages($response));
        $this->assertRow($new, ['status' => 'synced', 'qbo_doc_number' => "A{$new}", 'qbo_txn_id' => "txn-A{$new}", 'doc_type' => 'sales_receipt']);
        $this->assertRow($noItem, ['status' => 'failed', 'last_error_code' => 'item_missing']);
    }

    #[Test]
    public function one_reference_is_totalled_alone(): void
    {
        $this->insertOrder(self::REFERENCE);

        $response = $this->sync(['references' => [self::REFERENCE]])
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('counts', ['synced' => 1, 'already_synced' => 0, 'skipped' => 0, 'failed' => 0, 'not_attempted' => 0]);

        $number = 'A' . self::REFERENCE;
        self::assertSame([self::expected(self::REFERENCE, 'synced', 'sales_receipt', $number, "txn-{$number}")], self::withoutMessages($response));
    }

    #[Test]
    public function a_failed_row_is_synced_with_force_retry(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'item_missing']);

        $this->sync(['references' => [self::REFERENCE], 'force_retry' => true])->assertOk()->assertJsonPath('results.0.outcome', 'synced');
        $this->assertRow(self::REFERENCE, ['status' => 'synced']);
    }

    #[Test]
    public function a_mixed_request_refuses_by_order_then_row(): void
    {
        [$failed, $leased, $unknown, $live, $lapsed] = $references = \range(self::REFERENCE, self::REFERENCE + 4);
        $this->insertOrder($failed, state: ['lifecycle_status' => 'cancelled']);
        $this->insertOrder($leased, state: ['lifecycle_status' => 'cancelled']);
        $this->insertOrder($live);
        $this->insertOrder($lapsed);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'item_missing'], $failed);
        $this->insertSync(self::LIVE, $leased);
        $this->insertSync(self::LIVE, $live);
        $this->insertSync(self::LAPSED, $lapsed);

        $response = $this->sync(['references' => $references])->assertOk();

        self::assertSame([
            self::expected($failed, 'skipped', reason: ['code' => 'cancelled', 'retryable' => false]),
            self::expected($leased, 'skipped', reason: ['code' => 'cancelled', 'retryable' => false]),
            self::expected($unknown, 'skipped', reason: ['code' => 'order_not_found', 'retryable' => false]),
            self::expected($live, 'not_attempted', reason: ['code' => 'in_progress', 'retryable' => true]),
            self::expected($lapsed, 'synced', 'sales_receipt', "A{$lapsed}", "txn-A{$lapsed}"),
        ], self::withoutMessages($response));
    }

    #[Test]
    public function a_second_request_during_the_first_is_not_attempted(): void
    {
        $this->insertOrder(self::REFERENCE);
        $second = null;
        $this->posting->duringCreate = function () use (&$second): void {
            $second = $this->sync(['references' => [self::REFERENCE]]);
        };

        $this->sync(['references' => [self::REFERENCE]])->assertOk()->assertJsonPath('results.0.outcome', 'synced');

        self::assertInstanceOf(TestResponse::class, $second);
        $second->assertOk()
            ->assertJsonPath('results.0.outcome', 'not_attempted')
            ->assertJsonPath('results.0.reason.code', 'in_progress');
        self::assertCount(1, \array_keys($this->posting->calls, 'createDocuments', true));
    }

    #[Test]
    public function a_mapping_key_never_seeded_fails_the_order(): void
    {
        $this->insertOrder(self::REFERENCE);
        DB::table('quickbooks.mappings')->where('key', 'department')->delete();

        $this->assertFailed('mapping_missing', ['references' => [self::REFERENCE]]);
        self::assertSame([], $this->posting->documents);
    }

    #[Test]
    public function a_missing_item_fails_its_order_before_any_customer(): void
    {
        $this->insertOrder(self::REFERENCE, state: ['billing_name' => 'Gone Buyer'], sku: 'SKU-GONE');
        $this->insertOrder(self::OTHER_REFERENCE);
        $this->posting->missingSkus = ['SKU-GONE'];

        $this->assertFailed('item_missing', ['references' => [self::REFERENCE, self::OTHER_REFERENCE]])
            ->assertJsonPath('results.1.outcome', 'synced');

        self::assertSame(['Test Buyer, TE1 1ST'], \array_column($this->posting->customers, 'displayName'));
        self::assertCount(1, $this->posting->documents);
    }

    #[Test]
    public function a_missing_item_replaces_a_stored_unknown_outcome(): void
    {
        $this->insertOrder(self::REFERENCE, sku: 'SKU-GONE');
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'outcome_unknown']);
        $this->posting->missingSkus = ['SKU-GONE'];

        $this->assertFailed('item_missing', ['references' => [self::REFERENCE], 'force_retry' => true]);
    }

    #[Test]
    public function an_order_without_a_payment_method_fails_before_quickbooks(): void
    {
        $this->insertOrder(self::REFERENCE, state: ['payment_method_raw' => null]);

        $this->assertFailed('mapping_missing', ['references' => [self::REFERENCE]]);
        self::assertSame([], $this->posting->calls);
    }

    #[Test]
    public function a_build_failure_keeps_a_stored_unknown_outcome(): void
    {
        $this->insertOrder(self::REFERENCE, state: ['payment_method_raw' => null]);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'outcome_unknown']);

        $response = $this->assertFailed('outcome_unknown', ['references' => [self::REFERENCE], 'force_retry' => true])
            ->assertJsonPath('results.0.reason.retryable', true);

        self::assertStringContainsString('mapping_missing', (string) \json_encode($response->json('results.0.reason.message')));
        self::assertStringContainsString('mapping_missing', (string) \json_encode(DB::table('quickbooks.order_syncs')->value('last_error_message')));
        self::assertSame(['mapping_missing'], DB::table('quickbooks.order_sync_errors')->pluck('reason_code')->all());
    }

    #[Test]
    public function every_document_is_looked_up_before_anything_is_created(): void
    {
        $references = \range(self::REFERENCE, self::REFERENCE + 3);
        foreach ($references as $reference) {
            $this->insertOrder($reference);
        }
        $this->insertSync(['status' => 'pending'], $references[1]);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'outcome_unknown'], $references[2]);
        $this->insertSync(self::LAPSED, $references[3]);

        $this->sync(['references' => $references, 'force_retry' => true])->assertOk()->assertJsonPath('counts.synced', 4);

        self::assertSame(['findDocuments', 'findItems', 'findOrCreateCustomers', 'createDocuments'], $this->posting->calls);
        self::assertEqualsCanonicalizing(\array_map(static fn(int $reference): string => "A{$reference}", $references), $this->posting->lookedUp);
    }

    /**
     * @return iterable<string, array{array<string, mixed>|null, bool, DocType}>
     */
    public static function existingDocuments(): iterable
    {
        $sources = [
            'new' => [null, false],
            'new forced' => [null, true],
            'pending' => [['status' => 'pending'], false],
            'pending forced' => [['status' => 'pending'], true],
            'lapsed' => [self::LAPSED, false],
            'lapsed forced' => [self::LAPSED, true],
            'failed forced' => [['status' => 'failed', 'last_error_code' => 'item_missing'], true],
        ];
        // intentional: a voided document reaches the port as an ordinary match, so it repeats its type's data set.
        $documents = ['invoice' => DocType::Invoice, 'sales receipt' => DocType::SalesReceipt, 'voided' => DocType::SalesReceipt];
        foreach ($sources as $source => [$state, $forceRetry]) {
            foreach ($documents as $document => $type) {
                yield "{$source}, {$document}" => [$state, $forceRetry, $type];
            }
        }
    }

    /**
     * @param array<string, mixed>|null $state
     */
    #[Test]
    #[DataProvider('existingDocuments')]
    public function an_existing_document_is_already_synced(?array $state, bool $forceRetry, DocType $type): void
    {
        $this->insertOrder(self::REFERENCE);
        if ($state !== null) {
            $this->insertSync($state);
        }
        $number = 'A' . self::REFERENCE;
        $this->posting->existing = [new ExistingDocumentDTO($number, 'qbo-7', $type)];

        $response = $this->sync(['references' => [self::REFERENCE], 'force_retry' => $forceRetry])->assertOk();

        self::assertSame([self::expected(self::REFERENCE, 'already_synced', $type->value, $number, 'qbo-7')], self::withoutMessages($response));
        $this->assertRow(self::REFERENCE, ['status' => 'synced', 'doc_type' => $type->value, 'qbo_doc_number' => $number, 'qbo_txn_id' => 'qbo-7']);
        self::assertSame(['findDocuments'], $this->posting->calls);
    }

    #[Test]
    public function an_existing_document_of_the_orders_own_type_is_preferred(): void
    {
        $this->insertOrder(self::REFERENCE);
        $number = 'A' . self::REFERENCE;
        $this->posting->existing = [
            new ExistingDocumentDTO($number, 'qbo-7', DocType::Invoice),
            new ExistingDocumentDTO($number, 'qbo-8', DocType::SalesReceipt),
        ];

        $response = $this->sync(['references' => [self::REFERENCE]])->assertOk();

        self::assertSame([self::expected(self::REFERENCE, 'already_synced', 'sales_receipt', $number, 'qbo-8')], self::withoutMessages($response));
    }

    #[Test]
    public function only_orders_whose_customer_is_in_another_currency_fail(): void
    {
        [$usd, $gbp, $none] = $references = \range(self::REFERENCE, self::REFERENCE + 2);
        foreach (['usd' => $usd, 'gbp' => $gbp, 'none' => $none] as $name => $reference) {
            $this->insertOrder($reference, state: ['billing_name' => "Buyer {$name}", 'billing_email' => "{$name}@example.test"]);
        }
        $this->posting->currencies = ['usd@example.test' => 'USD', 'gbp@example.test' => 'GBP', 'none@example.test' => null];

        $response = $this->assertFailed('qbo_validation', ['references' => $references])
            ->assertJsonPath('results.1.outcome', 'synced')
            ->assertJsonPath('results.2.outcome', 'synced');

        self::assertStringContainsString('USD', (string) \json_encode($response->json('results.0.reason.message')));
        self::assertCount(2, $this->posting->documents);
    }

    #[Test]
    public function a_lost_lease_is_logged_and_the_posted_outcome_returned(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->posting->duringCreate = static function (): void {
            DB::table('quickbooks.order_syncs')->update(['claim_token' => '00000000-0000-4000-8000-000000000002']);
        };
        $errors = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $log) use (&$errors): void {
            if ($log->level === 'error') {
                $errors[] = [$log->message, $log->context];
            }
        });

        $this->sync(['references' => [self::REFERENCE]])
            ->assertOk()
            ->assertJsonPath('results.0.outcome', 'synced')
            ->assertJsonPath('results.0.qbo_txn_id', 'txn-A' . self::REFERENCE);

        self::assertSame([[
            'QuickBooks order sync lease lost before its outcome was recorded',
            ['reference' => self::REFERENCE, 'outcome' => 'synced', 'reason' => null, 'qbo_txn_id' => 'txn-A' . self::REFERENCE],
        ]], $errors);
        $this->assertRow(self::REFERENCE, ['status' => 'processing', 'qbo_txn_id' => null]);
    }

    #[Test]
    public function run_labels_reach_the_drafts(): void
    {
        $this->insertOrder(self::REFERENCE);
        \config(['quickbooks.doc_number_prefix' => 'T1', 'quickbooks.customer_name_suffix' => 'T1S']);

        $this->sync(['references' => [self::REFERENCE]])->assertOk()->assertJsonPath('results.0.qbo_doc_number', 'T1A' . self::REFERENCE);

        self::assertStringEndsWith('T1S', $this->posting->customers[0]->displayName);
    }

    #[Test]
    public function a_run_label_outside_the_sandbox_is_a_server_error_before_any_claim(): void
    {
        \config(['app.env' => 'production', 'quickbooks.environment' => 'production']);
        $this->app->forgetInstance(QuickBooksConfig::class);
        $this->insertOrder(self::REFERENCE);
        $this->insertOrder(self::OTHER_REFERENCE);

        $this->sync(['references' => [self::OTHER_REFERENCE]])->assertOk()->assertJsonPath('results.0.outcome', 'synced');
        \config(['quickbooks.doc_number_prefix' => 'T1']);
        $this->posting->calls = [];

        $this->sync(['references' => [self::REFERENCE]])->assertStatus(500);
        self::assertSame(0, DB::table('quickbooks.order_syncs')->where('reference', self::REFERENCE)->count());
        self::assertSame([], $this->posting->calls);
    }

    #[Test]
    public function a_passed_deadline_claims_nothing(): void
    {
        $references = $this->insertReleaseSet();
        $synced = self::REFERENCE + 5;
        $this->insertOrder($synced);
        $this->insertSync(['status' => 'synced', 'doc_type' => 'invoice', 'qbo_doc_number' => 'DOC-1', 'qbo_txn_id' => 'txn-1'], $synced);
        $before = DB::table('quickbooks.order_syncs')->orderBy('reference')->get()->toArray();

        $response = $this->sync(['references' => [...$references, $synced], 'force_retry' => true], Deadline::at(new DateTimeImmutable('-1 second')))
            ->assertOk();

        self::assertSame([
            ...\array_map(
                static fn(int $reference): array
                    => self::expected($reference, 'not_attempted', reason: ['code' => 'deadline_exceeded', 'retryable' => true]),
                $references,
            ),
            self::expected($synced, 'already_synced', 'invoice', 'DOC-1', 'txn-1'),
        ], self::withoutMessages($response));
        self::assertEquals($before, DB::table('quickbooks.order_syncs')->orderBy('reference')->get()->toArray());
        self::assertSame([], $this->posting->calls);
    }

    #[Test]
    public function a_claim_that_throws_releases_the_rows_claimed_before_it(): void
    {
        $references = $this->insertReleaseSet();
        $last = self::REFERENCE + 5;
        $this->insertOrder($last);
        $repository = $this->interceptRepository();
        $repository->beforeClaim = static function (int $reference) use ($last): void {
            if ($reference === $last) {
                throw new DatabaseOperationFailedException('claim', 'test');
            }
        };

        $this->sync(['references' => [...$references, $last], 'force_retry' => true])->assertStatus(500);

        $this->assertReleased($references);
        self::assertSame([], $this->posting->calls);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function steps(): iterable
    {
        yield from self::stepsBeforeTheBatchCreate();
        yield 'batch create' => ['createDocuments'];
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function stepsBeforeTheBatchCreate(): iterable
    {
        yield 'DocNumber lookup' => ['findDocuments'];
        yield 'item query' => ['findItems'];
        yield 'customer query' => ['customerQuery'];
        yield 'customer create' => ['customerCreate'];
    }

    #[Test]
    #[DataProvider('steps')]
    public function a_call_refused_for_its_deadline_releases_its_orders(string $step): void
    {
        $references = $this->insertReleaseSet();
        $this->posting->throws[$step] = new QuickBooksDeadlineExceededException(0.5);

        $response = $this->sync(['references' => $references, 'force_retry' => true])
            ->assertOk()
            ->assertJsonPath('counts.not_attempted', 5);

        self::assertSame(\array_map(
            static fn(int $reference): array
                => self::expected($reference, 'not_attempted', reason: ['code' => 'deadline_exceeded', 'retryable' => true]),
            $references,
        ), self::withoutMessages($response));
        $this->assertReleased($references);
    }

    #[Test]
    #[DataProvider('steps')]
    public function an_unavailable_call_fails_its_orders(string $step): void
    {
        $references = $this->insertReleaseSet();
        $this->posting->throws[$step] = new ExternalServiceUnavailableException('QuickBooks');

        $this->sync(['references' => $references, 'force_retry' => true])
            ->assertOk()
            ->assertJsonPath('counts.failed', 5)
            ->assertJsonPath('results.*.reason.code', \array_fill(0, 5, 'qbo_unavailable'));

        foreach ($references as $reference) {
            $this->assertRow($reference, ['status' => 'failed', 'last_error_code' => 'qbo_unavailable', 'claim_token' => null]);
            self::assertEquals([(object) ['trigger' => 'manual', 'reason_code' => 'qbo_unavailable']], self::errors($reference));
        }
        self::assertSame([], $this->posting->documents);
    }

    #[Test]
    #[DataProvider('stepsBeforeTheBatchCreate')]
    public function an_unavailable_call_keeps_a_stored_unknown_outcome(string $step): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'outcome_unknown']);
        $this->posting->throws[$step] = new ExternalServiceUnavailableException('QuickBooks');

        $response = $this->assertFailed('outcome_unknown', ['references' => [self::REFERENCE], 'force_retry' => true])
            ->assertJsonPath('results.0.reason.retryable', true);

        self::assertStringContainsString('qbo_unavailable', (string) \json_encode($response->json('results.0.reason.message')));
        self::assertStringContainsString('qbo_unavailable', (string) \json_encode(DB::table('quickbooks.order_syncs')->value('last_error_message')));
        self::assertEquals([(object) ['trigger' => 'manual', 'reason_code' => 'qbo_unavailable']], self::errors(self::REFERENCE));
    }

    #[Test]
    #[DataProvider('steps')]
    public function a_rejected_access_token_releases_the_rows_and_is_forgotten(string $step): void
    {
        $references = $this->insertReleaseSet();
        $this->posting->throws[$step] = new AuthenticationExpiredException('QuickBooks');

        $this->sync(['references' => $references, 'force_retry' => true])
            ->assertStatus(412)
            ->assertJsonPath('error.type', 'precondition_failed')
            ->assertJsonMissingPath('error.errors');

        $this->assertReleased($references);
        self::assertNull($this->app->make(QuickBooksAccessTokenCacheInterface::class)->get());
    }

    #[Test]
    public function a_failed_token_drop_hides_neither_the_rejection_nor_the_releases(): void
    {
        $references = $this->insertReleaseSet();
        $cache = new UnforgettableTokenCache($this->app->make(QuickBooksAccessTokenCacheInterface::class));
        $this->app->instance(QuickBooksAccessTokenCacheInterface::class, $cache);
        $this->posting->throws['findDocuments'] = new AuthenticationExpiredException('QuickBooks');

        $this->sync(['references' => $references, 'force_retry' => true])->assertStatus(412);

        self::assertSame(1, $cache->forgets);
        $this->assertReleased($references);
    }

    /**
     * @return iterable<string, array{Throwable, int}>
     */
    public static function customerCreateThrows(): iterable
    {
        yield 'deadline' => [new QuickBooksDeadlineExceededException(0.5), 200];
        yield 'unavailable' => [new ExternalServiceUnavailableException('QuickBooks'), 200];
        yield 'rejected token' => [new AuthenticationExpiredException('QuickBooks'), 412];
        yield 'unexpected result' => [new UnexpectedApiResultException('QuickBooks', 'test'), 502];
    }

    #[Test]
    #[DataProvider('customerCreateThrows')]
    public function a_customer_create_that_throws_leaves_the_records_before_it(Throwable $throw, int $status): void
    {
        [$held, $empty, $postable] = \range(self::REFERENCE, self::REFERENCE + 2);
        foreach ([$held, $empty, $postable] as $reference) {
            $this->insertOrder($reference);
        }
        DB::table('shopwired.order_products')->where('order_external_id', $empty)->delete();
        $this->posting->existing = [new ExistingDocumentDTO("A{$held}", 'qbo-7', DocType::Invoice)];
        $this->posting->throws['customerCreate'] = $throw;

        $this->sync(['references' => [$held, $empty, $postable]])->assertStatus($status);

        self::assertCount(1, $this->posting->customers);
        $this->assertRow($held, ['status' => 'synced', 'qbo_txn_id' => 'qbo-7']);
        $this->assertRow($empty, ['status' => 'failed', 'last_error_code' => 'zero_lines']);
    }

    /**
     * @return iterable<string, array{string, Throwable, int}>
     */
    public static function unhandledThrows(): iterable
    {
        foreach (self::stepsBeforeTheBatchCreate() as $name => [$step]) {
            yield "unexpected result from {$name}" => [$step, new UnexpectedApiResultException('QuickBooks', 'test'), 502];
            yield "fault from {$name}" => [
                $step,
                new QuickBooksFaultException('QuickBooks', new QuickBooksFault(QuickBooksFaultKind::Validation, '6000')),
                502,
            ];
            yield "error from {$name}" => [$step, new Error('test'), 500];
            yield "runtime exception from {$name}" => [$step, new RuntimeException('test'), 500];
        }
    }

    #[Test]
    #[DataProvider('unhandledThrows')]
    public function an_unhandled_throw_before_the_batch_create_releases_the_rows(string $step, Throwable $throw, int $status): void
    {
        $references = $this->insertReleaseSet();
        $this->posting->throws[$step] = $throw;

        $this->sync(['references' => $references, 'force_retry' => true])->assertStatus($status);

        $this->assertReleased($references);
        self::assertSame([], $this->posting->documents);
    }

    /**
     * @return iterable<string, array{Throwable, int}>
     */
    public static function mappingThrows(): iterable
    {
        yield 'repository unavailable' => [new ExternalServiceUnavailableException('Database'), 503];
        yield 'cache unavailable' => [new ExternalServiceUnavailableException('QuickBooks token cache'), 503];
        yield 'lock' => [new LockAcquisitionException('test', 1), 503];
        yield 'database' => [new DatabaseOperationFailedException('resolvedRefs', 'test'), 500];
    }

    #[Test]
    #[DataProvider('mappingThrows')]
    public function a_mapping_read_that_throws_releases_the_rows(Throwable $throw, int $status): void
    {
        $references = $this->insertReleaseSet();
        $this->app->instance(QuickBooksMappingRepositoryInterface::class, new InterceptedMappingRepository(
            $this->app->make(QuickBooksMappingRepositoryInterface::class),
            $throw,
        ));

        $this->sync(['references' => $references, 'force_retry' => true])->assertStatus($status);

        $this->assertReleased($references);
        self::assertSame(0, DB::table('quickbooks.order_syncs')->where('last_error_code', 'qbo_unavailable')->count());
        self::assertSame(['findDocuments', 'findItems'], $this->posting->calls);
    }

    #[Test]
    public function a_release_that_throws_does_not_replace_the_original_error(): void
    {
        $references = $this->insertReleaseSet();
        $released = 0;
        $this->interceptRepository()->beforeRelease = static function () use (&$released): void {
            $released++;

            throw new DatabaseOperationFailedException('release', 'test');
        };
        $this->posting->throws['findDocuments'] = new UnexpectedApiResultException('QuickBooks', 'test');

        $this->sync(['references' => $references, 'force_retry' => true])->assertStatus(502);

        self::assertSame(5, $released);
    }

    #[Test]
    public function a_record_that_throws_stops_no_other_record(): void
    {
        [$first, $second, $empty, $postable] = \range(self::REFERENCE, self::REFERENCE + 3);
        foreach ([$first, $second, $empty, $postable] as $reference) {
            $this->insertOrder($reference);
        }
        DB::table('shopwired.order_products')->where('order_external_id', $empty)->delete();
        $this->posting->existing = [
            new ExistingDocumentDTO("A{$first}", 'qbo-1', DocType::Invoice),
            new ExistingDocumentDTO("A{$second}", 'qbo-2', DocType::Invoice),
        ];
        $this->interceptRepository()->beforeSynced = static function (OrderSyncPostedDTO $posted): void {
            if ($posted->qboTxnId === 'qbo-1') {
                throw new DatabaseOperationFailedException('recordSynced', 'test');
            }
        };

        $this->sync(['references' => [$first, $second, $empty, $postable]])->assertStatus(500);

        $this->assertRow($first, ['status' => 'pending', 'claim_token' => null]);
        $this->assertRow($second, ['status' => 'synced', 'qbo_txn_id' => 'qbo-2']);
        $this->assertRow($empty, ['status' => 'failed', 'last_error_code' => 'zero_lines']);
        $this->assertRow($postable, ['status' => 'pending', 'claim_token' => null]);
        self::assertSame(['findDocuments'], $this->posting->calls);
    }

    /**
     * The port reports a timeout and a 5xx after the send as ambiguous documents.
     *
     * @return iterable<string, array{Throwable|null, string|null}>
     */
    public static function unknownBatchOutcomes(): iterable
    {
        yield 'times out' => [null, null];
        yield 'returns a 5xx' => [null, null];
        yield 'throws a runtime exception before its documents' => [new RuntimeException('test'), 'createDocuments'];
        yield 'throws a runtime exception after its documents' => [new RuntimeException('test'), 'afterCreate'];
        yield 'throws an error before its documents' => [new Error('test'), 'createDocuments'];
        yield 'throws an error after its documents' => [new Error('test'), 'afterCreate'];
        yield 'throws an unexpected result' => [new UnexpectedApiResultException('QuickBooks', 'test'), 'afterCreate'];
    }

    #[Test]
    #[DataProvider('unknownBatchOutcomes')]
    public function an_unknown_batch_outcome_fails_every_order(?Throwable $throw, ?string $step): void
    {
        $references = $this->insertReleaseSet();
        foreach ($references as $reference) {
            $this->posting->batchOutcomes["A{$reference}"] = BatchItemOutcomeResult::ambiguous();
        }
        if ($throw !== null && $step !== null) {
            $this->posting->throws[$step] = $throw;
        }

        $this->sync(['references' => $references, 'force_retry' => true])
            ->assertOk()
            ->assertJsonPath('counts.failed', 5)
            ->assertJsonPath('results.*.reason.code', \array_fill(0, 5, 'outcome_unknown'))
            ->assertJsonPath('results.*.reason.retryable', \array_fill(0, 5, true));

        foreach ($references as $reference) {
            $this->assertRow($reference, ['status' => 'failed', 'last_error_code' => 'outcome_unknown', 'claim_token' => null]);
            self::assertEquals([(object) ['trigger' => 'manual', 'reason_code' => 'outcome_unknown']], self::errors($reference));
        }
        self::assertSame(0, DB::table('quickbooks.order_syncs')->whereIn('status', ['pending', 'processing'])->count());
        self::assertSame(['findDocuments', 'findItems', 'findOrCreateCustomers', 'createDocuments'], $this->posting->calls);
    }

    #[Test]
    public function an_unknown_batch_outcome_is_logged_by_class_even_when_a_record_throws(): void
    {
        $references = $this->insertReleaseSet();
        $this->posting->throws['afterCreate'] = new RuntimeException('customer data');
        $failures = 0;
        $this->interceptRepository()->beforeFailed = static function () use (&$failures): void {
            $failures++;
            if ($failures === 1) {
                throw new DatabaseOperationFailedException('recordFailed', 'test');
            }
        };
        $errors = [];
        Event::listen(MessageLogged::class, static function (MessageLogged $log) use (&$errors): void {
            if ($log->level === 'error') {
                $errors[] = [$log->message, $log->context];
            }
        });

        $this->sync(['references' => $references, 'force_retry' => true])->assertStatus(500);

        self::assertSame(5, $failures);
        self::assertContains([
            'QuickBooks batch create outcome is unknown',
            ['references' => $references, 'exception' => RuntimeException::class],
        ], $errors);
        $this->assertRow($references[0], ['status' => 'processing', 'last_error_code' => null]);
        foreach (\array_slice($references, 1) as $reference) {
            $this->assertRow($reference, ['status' => 'failed', 'last_error_code' => 'outcome_unknown']);
        }
    }

    /**
     * @return iterable<string, array{Throwable, int}>
     */
    public static function recordThrows(): iterable
    {
        yield 'unavailable' => [new ExternalServiceUnavailableException('Database'), 503];
        yield 'database' => [new DatabaseOperationFailedException('recordSynced', 'test'), 500];
        yield 'runtime exception' => [new RuntimeException('test'), 500];
    }

    #[Test]
    #[DataProvider('recordThrows')]
    public function a_record_that_throws_after_the_batch_create_stops_no_other_record(Throwable $throw, int $status): void
    {
        $references = $this->insertReleaseSet();
        $this->posting->batchOutcomes["A{$references[2]}"] = BatchItemOutcomeResult::ambiguous();
        $this->posting->batchOutcomes["A{$references[3]}"] = BatchItemOutcomeResult::rateLimited(null);
        $unrecorded = "txn-A{$references[0]}";
        $released = 0;
        $repository = $this->interceptRepository();
        $repository->beforeSynced = static function (OrderSyncPostedDTO $posted) use ($unrecorded, $throw): void {
            if ($posted->qboTxnId === $unrecorded) {
                throw $throw;
            }
        };
        $repository->beforeRelease = static function () use (&$released): void {
            $released++;
        };

        $this->sync(['references' => $references, 'force_retry' => true])->assertStatus($status);

        self::assertSame(0, $released);
        $this->assertRow($references[0], ['status' => 'processing', 'qbo_txn_id' => null]);
        $this->assertRow($references[1], ['status' => 'synced', 'qbo_txn_id' => "txn-A{$references[1]}"]);
        $this->assertRow($references[2], ['status' => 'failed', 'last_error_code' => 'outcome_unknown']);
        $this->assertRow($references[3], ['status' => 'failed', 'last_error_code' => 'qbo_unavailable']);
        $this->assertRow($references[4], ['status' => 'synced', 'qbo_txn_id' => "txn-A{$references[4]}"]);
    }

    /**
     * @return iterable<string, array{CustomerFailureKind|QuickBooksFault, string}>
     */
    public static function customerFailures(): iterable
    {
        yield 'inactive' => [CustomerFailureKind::Inactive, 'qbo_validation'];
        yield 'name clash' => [CustomerFailureKind::NameClash, 'qbo_validation'];
        foreach (self::faults() as $name => [$fault, $code]) {
            yield "fault {$name}" => [$fault, $code];
        }
    }

    #[Test]
    #[DataProvider('customerFailures')]
    public function a_failed_customer_fails_its_order(CustomerFailureKind|QuickBooksFault $failure, string $code): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->posting->customerFailures['buyer@example.test'] = $failure;

        $this->assertFailed($code, ['references' => [self::REFERENCE]]);
        self::assertSame([], $this->posting->documents);
    }

    /**
     * @return iterable<string, array{BatchItemOutcomeResult, string}>
     */
    public static function failedBatchItems(): iterable
    {
        yield 'rate limited' => [BatchItemOutcomeResult::rateLimited(1), 'qbo_unavailable'];
        foreach (self::faults() as $name => [$fault, $code]) {
            yield "fault {$name}" => [BatchItemOutcomeResult::faulted($fault), $code];
        }
    }

    #[Test]
    #[DataProvider('failedBatchItems')]
    public function a_failed_batch_item_fails_its_order(BatchItemOutcomeResult $item, string $code): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->posting->batchOutcomes['A' . self::REFERENCE] = $item;

        $this->assertFailed($code, ['references' => [self::REFERENCE]]);
    }

    #[Test]
    public function an_order_that_cannot_be_built_makes_no_call(): void
    {
        $this->insertOrder(self::REFERENCE);
        $this->insertOrder(self::OTHER_REFERENCE);
        DB::table('shopwired.order_products')->where('order_external_id', self::REFERENCE)->delete();
        $this->changeLine(self::OTHER_REFERENCE, ['vat_rate' => '5']);

        $this->sync(['references' => [self::REFERENCE, self::OTHER_REFERENCE]])->assertOk();

        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => 'zero_lines']);
        $this->assertRow(self::OTHER_REFERENCE, ['status' => 'failed', 'last_error_code' => 'tax_rate_unsupported']);
        self::assertSame([], $this->posting->calls);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, array<string, mixed>, string}>
     */
    public static function lineDefects(): iterable
    {
        yield 'no SKU' => [[], ['sku' => ''], 'item_missing'];
        yield 'no VAT rate' => [['shipping_vat_rate' => null], [], 'tax_rate_unsupported'];
        yield 'negative VAT rate' => [[], ['vat_rate' => '-20'], 'tax_rate_unsupported'];
        yield 'quantity below one' => [[], ['quantity' => 0], 'qbo_validation'];
    }

    #[Test]
    public function orders_with_defective_lines_fail_and_the_postable_order_is_synced(): void
    {
        $expected = [];
        $reference = self::REFERENCE;
        foreach (self::lineDefects() as [$state, $line, $code]) {
            $this->insertOrder($reference, state: $state);
            $this->changeLine($reference, $line);
            $expected[$reference++] = $code;
        }
        $this->insertOrder($reference);

        $this->sync(['references' => [...\array_keys($expected), $reference]])
            ->assertOk()
            ->assertJsonPath('results.*.outcome', ['failed', 'failed', 'failed', 'failed', 'synced']);

        foreach ($expected as $failed => $code) {
            $this->assertRow($failed, ['status' => 'failed', 'last_error_code' => $code]);
        }
        $this->assertRow($reference, ['status' => 'synced', 'qbo_txn_id' => "txn-A{$reference}"]);
        self::assertSame(["A{$reference}"], $this->posting->lookedUp);
        self::assertCount(1, $this->posting->customers);
        self::assertCount(1, $this->posting->documents);
    }

    /**
     * @param array<string, mixed> $state
     * @param array<string, mixed> $line
     */
    #[Test]
    #[DataProvider('lineDefects')]
    public function a_defective_line_keeps_a_stored_unknown_outcome(array $state, array $line, string $code): void
    {
        $this->insertOrder(self::REFERENCE, state: $state);
        $this->changeLine(self::REFERENCE, $line);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'outcome_unknown']);

        $response = $this->assertFailed('outcome_unknown', ['references' => [self::REFERENCE], 'force_retry' => true])
            ->assertJsonPath('results.0.reason.retryable', true);

        self::assertStringContainsString($code, (string) \json_encode($response->json('results.0.reason.message')));
        self::assertStringContainsString($code, (string) \json_encode(DB::table('quickbooks.order_syncs')->value('last_error_message')));
        self::assertEquals([(object) ['trigger' => 'manual', 'reason_code' => $code]], self::errors(self::REFERENCE));
    }

    #[Test]
    public function each_failed_order_appends_one_error(): void
    {
        [$unpaid, $noSku, $empty, $taxed, $closed, $invalid] = $references = \range(self::REFERENCE, self::REFERENCE + 5);
        foreach ($references as $reference) {
            $this->insertOrder($reference, state: $reference === $unpaid ? ['payment_method_raw' => null] : []);
        }
        $this->changeLine($noSku, ['sku' => '']);
        DB::table('shopwired.order_products')->where('order_external_id', $empty)->delete();
        $this->changeLine($taxed, ['vat_rate' => '5']);
        $this->posting->batchOutcomes = [
            "A{$closed}" => BatchItemOutcomeResult::faulted(new QuickBooksFault(QuickBooksFaultKind::ClosedPeriod, '6210')),
            "A{$invalid}" => BatchItemOutcomeResult::faulted(new QuickBooksFault(QuickBooksFaultKind::Validation, '6000')),
        ];

        $this->sync(['references' => $references])->assertOk()->assertJsonPath('counts.failed', 6);

        $codes = ['mapping_missing', 'item_missing', 'zero_lines', 'tax_rate_unsupported', 'closed_period', 'qbo_validation'];
        foreach ($references as $index => $reference) {
            self::assertEquals([(object) ['trigger' => 'manual', 'reason_code' => $codes[$index]]], self::errors($reference));
        }
        self::assertSame(6, DB::table('quickbooks.order_sync_errors')->count());
    }

    #[Test]
    public function an_order_that_did_not_fail_appends_no_error(): void
    {
        [$new, $synced, $failed, $leased] = $references = \range(self::REFERENCE, self::REFERENCE + 3);
        foreach ($references as $reference) {
            $this->insertOrder($reference);
        }
        $this->insertSync(['status' => 'synced', 'doc_type' => 'invoice', 'qbo_doc_number' => 'DOC-1', 'qbo_txn_id' => 'txn-1'], $synced);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'item_missing'], $failed);
        $this->insertSync(self::LIVE, $leased);

        $this->sync(['references' => $references])
            ->assertOk()
            ->assertJsonPath('results.*.outcome', ['synced', 'already_synced', 'skipped', 'not_attempted']);

        $this->assertRow($new, ['status' => 'synced']);
        self::assertSame(0, DB::table('quickbooks.order_sync_errors')->count());
    }

    /**
     * @return iterable<string, array{QuickBooksFault, string}>
     */
    private static function faults(): iterable
    {
        foreach (QuickBooksFaultKind::cases() as $kind) {
            yield $kind->value => [
                new QuickBooksFault($kind, '6000'),
                $kind === QuickBooksFaultKind::ClosedPeriod ? 'closed_period' : 'qbo_validation',
            ];
        }
    }

    /**
     * @param array<string, mixed> $line
     */
    private function changeLine(int $reference, array $line): void
    {
        if ($line !== []) {
            self::assertSame(1, DB::table('shopwired.order_products')->where('order_external_id', $reference)->update($line));
        }
    }

    /**
     * @return list<object>
     */
    private static function errors(int $reference): array
    {
        $id = DB::table('quickbooks.order_syncs')->where('reference', $reference)->value('id');

        return \array_values(DB::table('quickbooks.order_sync_errors')->where('order_sync_id', $id)->get(['trigger', 'reason_code'])->all());
    }

    /**
     * Postable orders with new customers, one per claimable row state.
     *
     * @return non-empty-list<int> no row, pending, failed, lapsed with a stored reason, lapsed with none
     */
    private function insertReleaseSet(): array
    {
        $references = \range(self::REFERENCE, self::REFERENCE + 4);
        foreach ($references as $reference) {
            $this->insertOrder($reference, state: ['billing_name' => "Buyer {$reference}", 'billing_email' => "{$reference}@example.test"]);
        }
        $this->insertSync(['status' => 'pending'], $references[1]);
        $this->insertSync(['status' => 'failed', 'last_error_code' => 'item_missing'], $references[2]);
        $this->insertSync([...self::LAPSED, 'last_error_code' => 'item_missing'], $references[3]);
        $this->insertSync(self::LAPSED, $references[4]);

        return $references;
    }

    /**
     * Each row of the release set was claimed, then returned to the state its stored reason gives it, with no error row.
     *
     * @param list<int> $references
     */
    private function assertReleased(array $references): void
    {
        $states = [['pending', 1], ['pending', 2], ['failed', 2], ['failed', 2], ['pending', 2]];
        foreach ($references as $index => $reference) {
            [$status, $attempts] = $states[$index];
            $this->assertRow($reference, [
                'status' => $status,
                'attempts' => $attempts,
                'last_error_code' => $status === 'failed' ? 'item_missing' : null,
                'claim_token' => null,
                'lease_expires_at' => null,
            ]);
        }
        $ids = DB::table('quickbooks.order_syncs')->whereIn('reference', $references)->pluck('id');
        self::assertSame(0, DB::table('quickbooks.order_sync_errors')->whereIn('order_sync_id', $ids)->count());
    }

    private function interceptRepository(): InterceptedOrderSyncRepository
    {
        $repository = new InterceptedOrderSyncRepository($this->app->make(OrderSyncRepositoryInterface::class));
        $this->app->instance(OrderSyncRepositoryInterface::class, $repository);

        return $repository;
    }

    /**
     * @param array{code: string, retryable: bool}|null $reason
     *
     * @return array<string, mixed>
     */
    private static function expected(
        int $reference,
        string $outcome,
        ?string $docType = null,
        ?string $docNumber = null,
        ?string $txnId = null,
        ?array $reason = null,
    ): array {
        return [
            'reference' => $reference,
            'outcome' => $outcome,
            'doc_type' => $docType,
            'qbo_doc_number' => $docNumber,
            'qbo_txn_id' => $txnId,
            'reason' => $reason,
        ];
    }

    /**
     * @param TestResponse<Response> $response
     *
     * @return list<array<string, mixed>>
     */
    private static function withoutMessages(TestResponse $response): array
    {
        $results = [];
        foreach ($response->json('results') as $result) {
            if ($result['reason'] !== null) {
                self::assertIsString($result['reason']['message']);
                self::assertNotSame('', $result['reason']['message']);
                unset($result['reason']['message']);
            }
            $results[] = $result;
        }

        return $results;
    }

    /**
     * The first reference failed with this code, in the response and in its row.
     *
     * @param array<string, mixed> $body
     *
     * @return TestResponse<Response>
     */
    private function assertFailed(string $code, array $body): TestResponse
    {
        $response = $this->sync($body)
            ->assertOk()
            ->assertJsonPath('results.0.outcome', 'failed')
            ->assertJsonPath('results.0.reason.code', $code);
        $this->assertRow(self::REFERENCE, ['status' => 'failed', 'last_error_code' => $code]);

        return $response;
    }

    /**
     * @param array<string, mixed> $expected
     */
    private function assertRow(int $reference, array $expected): void
    {
        self::assertSame($expected, (array) DB::table('quickbooks.order_syncs')->where('reference', $reference)->first(\array_keys($expected)));
    }

    private function cacheToken(): void
    {
        $this->app->make(QuickBooksAccessTokenCacheInterface::class)
            ->put(new ApiKeyToken('test-access-token'), CarbonImmutable::now()->addHour());
    }

    /**
     * @param array<string, mixed> $state
     */
    private function insertSync(array $state, int $reference = self::REFERENCE): void
    {
        DB::table('quickbooks.order_syncs')->insert([
            'realm_id' => self::REALM,
            'reference' => $reference,
            'order_external_id' => $reference,
            'attempts' => 1,
            'last_attempted_at' => '2026-11-01 12:00:00+00',
            ...$state,
        ]);
    }

    /**
     * The chunk's results, or the error it threw under the status the app gives that error.
     *
     * @param array{references: non-empty-list<int>, force_retry?: bool} $body
     *
     * @return TestResponse<Response>
     */
    private function sync(array $body, ?Deadline $deadline = null): TestResponse
    {
        $chunk = new OrderSyncChunkDTO(self::REALM, $body['references'], $body['force_retry'] ?? false, OrderSyncTrigger::Manual, null);

        try {
            $results = $this->app->make(SyncOrderChunkUseCase::class)->execute(
                new ApiKeyToken('test-access-token'),
                $chunk,
                $deadline ?? Deadline::after(CarbonImmutable::now(), SyncQuickBooksOrderChunkJob::DEADLINE_SECONDS),
            );
        } catch (Throwable $e) {
            return TestResponse::fromBaseResponse($this->app->make(ExceptionHandler::class)->render(self::apiRequest(), $e));
        }

        return TestResponse::fromBaseResponse(new JsonResponse([
            'total' => \count($results->results),
            'counts' => $results->counts(),
            'results' => \array_map(self::resultBody(...), $results->results),
        ]));
    }

    private static function apiRequest(): Request
    {
        $request = Request::create('/api/quickbooks/orders/sync', 'POST', server: ['HTTP_ACCEPT' => 'application/json']);
        // intentional: the order-sync error statuses apply to these named routes only.
        $request->setRouteResolver(static fn(): Route => new Route('POST', 'api/quickbooks/orders/sync', [])->name('quickbooks.orders.sync'));

        return $request;
    }

    /**
     * @return array<string, mixed>
     */
    private static function resultBody(OrderSyncResultDTO $result): array
    {
        return [
            'reference' => $result->reference,
            'outcome' => $result->outcome->value,
            'doc_type' => $result->docType?->value,
            'qbo_doc_number' => $result->qboDocNumber,
            'qbo_txn_id' => $result->qboTxnId,
            'reason' => $result->reason === null ? null : [
                'code' => $result->reason->value,
                'message' => $result->message,
                'retryable' => $result->reason->retryable(),
            ],
        ];
    }
}
