<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ExistingDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use App\Application\QuickBooks\OrderSync\Enums\BatchOutcomeKind;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\QuickBooks\OrderSync\Enums\DocType;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksBatchExecution;
use App\Infrastructure\QuickBooks\QuickBooksClient;
use App\Infrastructure\QuickBooks\QuickBooksCustomerProvisioner;
use App\Infrastructure\QuickBooks\QuickBooksDocumentBatchCreator;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use DateTimeImmutable;
use Exception;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\Data\IPPCompanyInfo;
use QuickBooksOnline\API\Data\IPPInvoice;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPSalesReceipt;
use stdClass;
use Tests\Unit\Application\QuickBooks\OrderSync\DocumentDraftFixture;

#[CoversClass(QuickBooksClient::class)]
final class QuickBooksClientTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    #[Test]
    public function it_returns_the_company_name(): void
    {
        $this->assertSame('Sandbox Company_US_1', $this->clientReturning(self::companyInfo('Sandbox Company_US_1'))->companyName(new ApiKeyToken('access')));
    }

    #[Test]
    #[DataProvider('unusableResults')]
    public function an_unusable_result_raises_unexpected_api_result(mixed $result, string $reason): void
    {
        try {
            $this->clientReturning($result)->companyName(new ApiKeyToken('access'));
            $this->fail('Expected UnexpectedApiResultException');
        } catch (UnexpectedApiResultException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    /**
     * @return array<string, array{mixed, string}>
     */
    public static function unusableResults(): array
    {
        return [
            'null result' => [null, 'CompanyInfo missing from response'],
            'wrong type' => [new stdClass(), 'CompanyInfo missing from response'],
            'no name' => [self::companyInfo(null), 'CompanyName blank'],
            'blank name' => [self::companyInfo('   '), 'CompanyName blank'],
        ];
    }

    #[Test]
    public function a_transport_failure_propagates(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('companyInfo')->once()->andThrow(new AuthenticationExpiredException('QuickBooks'));

        $this->expectException(AuthenticationExpiredException::class);

        self::client($transport)->companyName(new ApiKeyToken('access'));
    }

    #[Test]
    public function find_items_matches_rows_and_lists_the_missing_skus(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('query')->once()
            ->with(Mockery::any(), "SELECT * FROM Item WHERE Name IN ('ABC-1', 'O\\'Gone', '12345') MAXRESULTS 1000", Mockery::any())
            ->andReturn([self::item('abc-1', '7'), self::item('12345', 9)]);

        $gone = Sku::fromString("O'Gone");
        $result = self::client($transport)->findItems(new ApiKeyToken('access'), [Sku::fromString('ABC-1'), $gone, Sku::fromString('12345'), Sku::fromString('abc-1')], self::deadline());

        $this->assertSame('7', $result->itemIdFor(Sku::fromString('ABC-1')));
        $this->assertSame('9', $result->itemIdFor(Sku::fromString('12345')));
        $this->assertSame([$gone], $result->missing);
    }

    #[Test]
    public function find_items_queries_more_than_a_hundred_skus_in_chunks_and_on_every_call(): void
    {
        $skus = \array_map(static fn(int $n): Sku => Sku::fromString("SKU-{$n}"), \range(1, 101));
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('query')->times(4)->andReturn([]);
        $client = self::client($transport);

        $client->findItems(new ApiKeyToken('access'), $skus, self::deadline());
        $result = $client->findItems(new ApiKeyToken('access'), $skus, self::deadline());

        $this->assertCount(101, $result->missing);
    }

    #[Test]
    public function find_items_with_no_skus_makes_no_call(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldNotReceive('query');

        $this->assertSame([], self::client($transport)->findItems(new ApiKeyToken('access'), [], self::deadline())->missing);
    }

    /**
     * @return array<string, array{object}>
     */
    public static function unusableItemRows(): array
    {
        return [
            'another entity' => [new IPPCompanyInfo()],
            'no id' => [self::item('ABC-1', null)],
            'blank name' => [self::item(' ', '7')],
        ];
    }

    #[Test]
    #[DataProvider('unusableItemRows')]
    public function an_unusable_item_row_is_unexpected(object $row): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('query')->once()->andReturn([$row]);

        $this->expectException(UnexpectedApiResultException::class);

        self::client($transport)->findItems(new ApiKeyToken('access'), [Sku::fromString('ABC-1')], self::deadline());
    }

    #[Test]
    public function find_documents_returns_every_match_of_both_types_and_omits_the_rest(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        self::expectDocumentQuery($transport, 'Invoice', "('A1', 'A2', 'A3')", [self::invoice('A1', '7'), self::invoice('A1', 8)]);
        self::expectDocumentQuery($transport, 'SalesReceipt', "('A1', 'A2', 'A3')", [self::receipt('A1', '9'), self::receipt('A2', '10')]);

        $result = self::client($transport)->findDocuments(new ApiKeyToken('access'), ['A1', 'A2', 'A3'], self::deadline());

        $this->assertEquals([
            new ExistingDocumentDTO('A1', '7', DocType::Invoice),
            new ExistingDocumentDTO('A1', '8', DocType::Invoice),
            new ExistingDocumentDTO('A1', '9', DocType::SalesReceipt),
            new ExistingDocumentDTO('A2', '10', DocType::SalesReceipt),
        ], $result->documents);
        $this->assertSame(['7', '8', '9'], \array_column($result->forDocNumber('A1'), 'qboId'));
        $this->assertSame(['10'], \array_column($result->forDocNumber('A2'), 'qboId'));
        $this->assertSame([], $result->forDocNumber('A3'));
    }

    #[Test]
    public function find_documents_escapes_trims_and_matches_ignoring_case(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $list = "('O\\'A1\\\\x', '123')";
        self::expectDocumentQuery($transport, 'Invoice', $list, [self::invoice("o'a1\\X", '7')]);
        self::expectDocumentQuery($transport, 'SalesReceipt', $list, [self::receipt('123', '8')]);

        $result = self::client($transport)->findDocuments(new ApiKeyToken('access'), ["O'A1\\x", " o'a1\\X ", '123'], self::deadline());

        $this->assertSame(['7'], \array_column($result->forDocNumber("O'A1\\x"), 'qboId'));
        $this->assertSame(['7'], \array_column($result->forDocNumber(" o'a1\\x "), 'qboId'));
        $this->assertSame(['8'], \array_column($result->forDocNumber('123'), 'qboId'));
    }

    #[Test]
    public function find_documents_sends_one_query_per_type_per_hundred_distinct_numbers(): void
    {
        $numbers = \array_map(static fn(int $n): string => "A{$n}", \range(1, 101));
        $sent = [];
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('query')->times(4)->andReturnUsing(static function (ApiKeyToken $token, string $query) use (&$sent): array {
            $sent[] = [\explode(' ', $query)[4], \mb_substr_count($query, "'A")];

            return [];
        });

        self::client($transport)->findDocuments(new ApiKeyToken('access'), [...$numbers, 'a1', 'A100'], self::deadline());

        $this->assertSame([['Invoice', 100], ['SalesReceipt', 100], ['Invoice', 1], ['SalesReceipt', 1]], $sent);
    }

    #[Test]
    public function find_documents_with_no_numbers_makes_no_call(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldNotReceive('query');

        $this->assertSame([], self::client($transport)->findDocuments(new ApiKeyToken('access'), [], self::deadline())->documents);
    }

    /**
     * @return array<string, array{Exception}>
     */
    public static function documentQueryFailures(): array
    {
        return [
            'rejected' => [new QuickBooksFaultException('QuickBooks', new QuickBooksFault(QuickBooksFaultKind::Validation, '4000', null, 'Rejected'))],
            'unavailable' => [new ExternalServiceUnavailableException('QuickBooks')],
            'deadline' => [new QuickBooksDeadlineExceededException(-1)],
            'unauthenticated' => [new AuthenticationExpiredException('QuickBooks')],
        ];
    }

    #[Test]
    #[DataProvider('documentQueryFailures')]
    public function a_failed_first_document_query_throws(Exception $failure): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('query')->once()->andThrow($failure);

        $this->expectExceptionObject($failure);

        self::client($transport)->findDocuments(new ApiKeyToken('access'), ['A1'], self::deadline());
    }

    #[Test]
    #[DataProvider('documentQueryFailures')]
    public function a_failed_second_document_query_throws_after_the_first_matched(Exception $failure): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('query')->once()->ordered()->andReturn([self::invoice('A1', '7')]);
        $transport->shouldReceive('query')->once()->ordered()->andThrow($failure);

        $this->expectExceptionObject($failure);

        self::client($transport)->findDocuments(new ApiKeyToken('access'), ['A1'], self::deadline());
    }

    /**
     * @return array<string, array{list<object>, list<object>, string}>
     */
    public static function unusableDocumentRows(): array
    {
        return [
            'another entity' => [[self::item('A1', '7')], [], 'Document query returned another entity'],
            'a receipt for the invoice query' => [[self::receipt('A1', '7')], [], 'Document query returned another entity'],
            'invoice with no id' => [[self::invoice('A1', null)], [], 'Document number or id blank'],
            'invoice with no number' => [[self::invoice(null, '7')], [], 'Document number or id blank'],
            'receipt with no id' => [[], [self::receipt('A1', null)], 'Document number or id blank'],
            'receipt with a blank number' => [[], [self::receipt(' ', '7')], 'Document number or id blank'],
            'row limit hit' => [\array_fill(0, 1000, self::invoice('A1', '7')), [], 'Document query hit the row limit'],
        ];
    }

    /**
     * @param list<object> $invoices
     * @param list<object> $receipts
     */
    #[Test]
    #[DataProvider('unusableDocumentRows')]
    public function unusable_document_rows_are_unexpected(array $invoices, array $receipts, string $reason): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('query')->andReturn($invoices, $receipts);

        try {
            self::client($transport)->findDocuments(new ApiKeyToken('access'), ['A1'], self::deadline());
            $this->fail('Expected UnexpectedApiResultException');
        } catch (UnexpectedApiResultException $e) {
            $this->assertSame($reason, $e->reason);
        }
    }

    #[Test]
    public function find_or_create_customers_delegates_to_the_provisioner(): void
    {
        $drafts = [new CustomerDraftDTO('Jo Bloggs', '', '', '', '', '', StructuredAddressDTO::fromContact(new OrderContactDTO('', '', '', '', '', '', '', '')))];
        $expected = CustomerResolutionResult::of([]);
        $provisioner = Mockery::mock(QuickBooksCustomerProvisioner::class);
        $provisioner->shouldReceive('findOrCreate')->once()->with(Mockery::any(), $drafts, Mockery::any())->andReturn($expected);

        $this->assertSame($expected, self::client(Mockery::mock(QuickBooksTransport::class), $provisioner)->findOrCreateCustomers(new ApiKeyToken('access'), $drafts, self::deadline()));
    }

    #[Test]
    public function create_documents_delegates_to_the_batch_creator(): void
    {
        $documents = [new PostableDocumentDTO(DocumentDraftFixture::receipt(), DocumentDraftFixture::refs())];
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('executeBatch')->once()
            ->with(Mockery::any(), Mockery::on(static fn(array $entities): bool => \count($entities) === 1 && $entities[0] instanceof IPPSalesReceipt), Mockery::any())
            ->andReturn(QuickBooksBatchExecution::everyItem(BatchItemOutcomeResult::ambiguous()));

        $outcomes = self::client($transport)->createDocuments(new ApiKeyToken('access'), $documents, self::deadline());

        $this->assertSame([BatchOutcomeKind::Ambiguous], \array_map(static fn(BatchItemOutcomeResult $outcome): BatchOutcomeKind => $outcome->kind, $outcomes));
    }

    private static function client(QuickBooksTransport $transport, ?QuickBooksCustomerProvisioner $provisioner = null): QuickBooksClient
    {
        return new QuickBooksClient(
            $transport,
            $provisioner ?? new QuickBooksCustomerProvisioner($transport, new NullLogger()),
            new QuickBooksDocumentBatchCreator($transport, new NullLogger()),
            new NullLogger(),
        );
    }

    /**
     * @param list<object> $rows
     */
    private static function expectDocumentQuery(QuickBooksTransport&MockInterface $transport, string $entity, string $list, array $rows): void
    {
        $transport->shouldReceive('query')->once()
            ->with(Mockery::any(), "SELECT Id, DocNumber FROM {$entity} WHERE DocNumber IN {$list} MAXRESULTS 1000", Mockery::any())
            ->andReturn($rows);
    }

    private static function invoice(?string $docNumber, string|int|null $id): IPPInvoice
    {
        $invoice = new IPPInvoice();
        $invoice->DocNumber = $docNumber;
        $invoice->Id = $id;

        return $invoice;
    }

    private static function receipt(?string $docNumber, string|int|null $id): IPPSalesReceipt
    {
        $receipt = new IPPSalesReceipt();
        $receipt->DocNumber = $docNumber;
        $receipt->Id = $id;

        return $receipt;
    }

    private static function item(string $name, string|int|null $id): IPPItem
    {
        $item = new IPPItem();
        $item->Name = $name;
        $item->Id = $id;

        return $item;
    }

    private static function deadline(): Deadline
    {
        return Deadline::after(new DateTimeImmutable(), 30);
    }

    private static function companyInfo(?string $name): IPPCompanyInfo
    {
        $companyInfo = new IPPCompanyInfo();
        $companyInfo->CompanyName = $name;

        return $companyInfo;
    }

    private function clientReturning(mixed $result): QuickBooksClient
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('companyInfo')->once()->andReturn($result);

        return self::client($transport);
    }
}
