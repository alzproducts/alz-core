<?php

declare(strict_types=1);

namespace Tests\Feature\Presentation\Console\Commands;

use App\Application\Contracts\QuickBooks\QuickBooksAccessTokenCacheInterface;
use App\Application\Contracts\QuickBooks\QuickBooksConnectionRepositoryInterface;
use App\Application\Contracts\QuickBooks\QuickBooksOrderPostingInterface;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\Results\DuplicateDocNumbersResult;
use App\Application\QuickBooks\OrderSync\UseCases\AuditDuplicateDocNumbersUseCase;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\ValueObjects\QuickBooksConnection;
use App\Presentation\Console\Commands\QuickBooksDuplicateDocNumbersCommand;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use Tests\Feature\Concerns\InsertsSourceOrders;
use Tests\Fixtures\QuickBooks\FakeQuickBooksOrderPosting;
use Tests\TestCase;

#[CoversClass(QuickBooksDuplicateDocNumbersCommand::class)]
#[CoversClass(AuditDuplicateDocNumbersUseCase::class)]
#[CoversClass(DuplicateDocNumbersResult::class)]
final class QuickBooksDuplicateDocNumbersCommandTest extends TestCase
{
    use DatabaseTransactions;
    use InsertsSourceOrders;

    private const string HEADER = 'doc_number,type,qbo_id';

    private const int FIRST = 890000101;

    private const int SECOND = 890000102;

    private const int THIRD = 890000103;

    private const int FOURTH = 890000104;

    private FakeQuickBooksOrderPosting $posting;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        \config(['quickbooks.doc_number_prefix' => '']);
        $this->app->instance(QuickBooksOrderPostingInterface::class, $this->posting = new FakeQuickBooksOrderPosting());
        DB::table('public.quickbooks_connections')->where('environment', 'sandbox')->delete();
        $this->app->make(QuickBooksConnectionRepositoryInterface::class)->save(new QuickBooksConnection(
            refreshToken: new ApiKeyToken('test-refresh-token'),
            refreshTokenExpiresAt: CarbonImmutable::now()->addDays(100),
            refreshTokenHardExpiresAt: null,
        ));
        $this->app->make(QuickBooksAccessTokenCacheInterface::class)
            ->put(new ApiKeyToken('test-access-token'), CarbonImmutable::now()->addHour());
    }

    #[Test]
    public function a_number_on_an_invoice_and_a_sales_receipt_has_one_row_naming_both(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00');
        $this->posting->existing = [
            new ExistingDocumentDTO('A' . self::FIRST, '11', DocType::Invoice),
            new ExistingDocumentDTO('A' . self::FIRST, '22', DocType::SalesReceipt),
        ];

        $this->assertReport(['A' . self::FIRST . ',invoice,11,sales_receipt,22'], $this->report());
    }

    #[Test]
    public function two_invoices_differing_only_in_letter_case_have_one_row_naming_both(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00');
        $this->posting->existing = [
            new ExistingDocumentDTO('A' . self::FIRST, '11', DocType::Invoice),
            new ExistingDocumentDTO('a' . self::FIRST, '12', DocType::Invoice),
        ];

        $this->assertReport(['A' . self::FIRST . ',invoice,11,invoice,12'], $this->report());
    }

    #[Test]
    public function a_number_on_one_document_has_no_row(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00');
        $this->posting->existing = [new ExistingDocumentDTO('A' . self::FIRST, '11', DocType::Invoice)];

        $this->assertReport([], $this->report());
    }

    #[Test]
    public function it_looks_up_every_order_in_the_london_range_cancelled_included_and_no_other(): void
    {
        $this->insertOrder(self::FIRST, '2001-06-09 22:59:59+00');
        $this->insertOrder(self::SECOND, '2001-06-09 23:00:00+00', ['lifecycle_status' => 'cancelled']);
        $this->insertOrder(self::THIRD, '2001-06-11 22:59:59+00');
        $this->insertOrder(self::FOURTH, '2001-06-11 23:00:00+00');

        $this->assertReport([], $this->report('2001-06-10', '2001-06-11'));

        self::assertSame(['A' . self::SECOND, 'A' . self::THIRD], $this->posting->lookedUp);
    }

    #[Test]
    public function a_range_past_one_chunk_looks_up_every_number_and_reports_a_later_duplicate(): void
    {
        $references = \range(890000201, 890000301);
        foreach ($references as $reference) {
            $this->insertOrder($reference, '2001-01-10 10:00:00+00');
        }
        $this->posting->existing = [
            new ExistingDocumentDTO('A890000301', '11', DocType::Invoice),
            new ExistingDocumentDTO('A890000301', '12', DocType::Invoice),
        ];

        $tester = $this->report();

        $this->assertReport(['A890000301,invoice,11,invoice,12'], $tester);
        self::assertSame(['findDocuments', 'findDocuments'], $this->posting->calls);
        self::assertSame(\array_map(static fn(int $reference): string => "A{$reference}", $references), $this->posting->lookedUp);
        self::assertSame("orders_checked: 101\nduplicate_doc_numbers: 1\nduplicate_documents: 2\n", $tester->getErrorOutput(true));
    }

    #[Test]
    public function it_looks_up_numbers_with_the_run_label_prefix(): void
    {
        \config(['quickbooks.environment' => 'sandbox', 'quickbooks.doc_number_prefix' => 'T1-']);
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00');

        $this->assertReport([], $this->report());

        self::assertSame(['T1-A' . self::FIRST], $this->posting->lookedUp);
    }

    #[Test]
    public function a_run_finding_duplicates_only_looks_documents_up(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00');
        $this->posting->existing = [
            new ExistingDocumentDTO('A' . self::FIRST, '11', DocType::Invoice),
            new ExistingDocumentDTO('A' . self::FIRST, '22', DocType::SalesReceipt),
        ];

        $this->assertReport(['A' . self::FIRST . ',invoice,11,sales_receipt,22'], $this->report());

        self::assertSame(['findDocuments'], $this->posting->calls);
    }

    #[Test]
    public function it_writes_the_counts_to_stderr_and_logs_them_once_at_info(): void
    {
        foreach ([self::FIRST, self::SECOND, self::THIRD] as $reference) {
            $this->insertOrder($reference, '2001-01-10 10:00:00+00');
        }
        $this->posting->existing = [
            new ExistingDocumentDTO('A' . self::FIRST, '11', DocType::Invoice),
            new ExistingDocumentDTO('A' . self::FIRST, '12', DocType::Invoice),
            new ExistingDocumentDTO('A' . self::SECOND, '21', DocType::Invoice),
            new ExistingDocumentDTO('A' . self::SECOND, '22', DocType::SalesReceipt),
            new ExistingDocumentDTO('A' . self::SECOND, '23', DocType::SalesReceipt),
            new ExistingDocumentDTO('A' . self::THIRD, '31', DocType::Invoice),
        ];
        $counted = [];
        Log::listen(static function (MessageLogged $log) use (&$counted): void {
            if (isset($log->context['counts'])) {
                $counted[] = [$log->level, $log->context['counts']];
            }
        });

        $tester = $this->report();

        self::assertSame("orders_checked: 3\nduplicate_doc_numbers: 2\nduplicate_documents: 5\n", $tester->getErrorOutput(true));
        self::assertSame([['info', ['orders_checked' => 3, 'duplicate_doc_numbers' => 2, 'duplicate_documents' => 5]]], $counted);
    }

    #[Test]
    public function a_range_with_no_orders_calls_no_quickbooks(): void
    {
        $tester = $this->report();

        $this->assertReport([], $tester);
        self::assertSame([], $this->posting->calls);
        self::assertSame("orders_checked: 0\nduplicate_doc_numbers: 0\nduplicate_documents: 0\n", $tester->getErrorOutput(true));
    }

    #[Test]
    public function a_rejected_token_is_forgotten_and_the_run_fails_with_no_csv(): void
    {
        $this->insertOrder(self::FIRST, '2001-01-10 10:00:00+00');
        $this->posting->throws['findDocuments'] = new AuthenticationExpiredException('QuickBooks');

        $tester = $this->report();

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Stopped: AuthenticationExpiredException', $tester->getErrorOutput());
        self::assertNull($this->app->make(QuickBooksAccessTokenCacheInterface::class)->get());
    }

    #[Test]
    public function a_refused_range_exits_non_zero_with_no_csv_and_no_lookup(): void
    {
        $tester = $this->report('2001-01-12', '2001-01-11');

        self::assertSame(1, $tester->getStatusCode());
        self::assertSame('', $tester->getDisplay());
        self::assertStringContainsString('Check: --', $tester->getErrorOutput());
        self::assertSame([], $this->posting->calls);
    }

    /**
     * @param list<string> $rows
     */
    private function assertReport(array $rows, CommandTester $tester): void
    {
        self::assertSame(\implode("\n", [self::HEADER, ...$rows, '']), $tester->getDisplay(true));
        self::assertSame(0, $tester->getStatusCode());
    }

    private function report(string $from = '2001-01-10', string $to = '2001-01-12'): CommandTester
    {
        $command = $this->app->make(QuickBooksDuplicateDocNumbersCommand::class);
        $command->setLaravel($this->app);
        $tester = new CommandTester($command);
        $tester->execute(['--from' => $from, '--to' => $to], ['capture_stderr_separately' => true]);

        return $tester;
    }
}
