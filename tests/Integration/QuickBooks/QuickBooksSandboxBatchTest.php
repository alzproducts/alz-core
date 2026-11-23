<?php

declare(strict_types=1);

namespace Tests\Integration\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\DocumentCoreDTO;
use App\Application\QuickBooks\OrderSync\DTOs\GoodsLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\InvoiceDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ResolvedDocumentRefsDTO;
use App\Application\QuickBooks\OrderSync\DTOs\SalesReceiptDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\ShippingLineDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use App\Application\QuickBooks\OrderSync\Enums\BatchOutcomeKind;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\Results\ItemLookupResult;
use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\QuickBooks\OrderSync\Enums\FixedRefKey;
use App\Domain\QuickBooks\OrderSync\Enums\PaymentKey;
use App\Domain\QuickBooks\OrderSync\Enums\TaxKey;
use App\Domain\Shared\Money\ValueObjects\DecimalMoney;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksInvoiceMapper;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksSalesReceiptMapper;
use App\Infrastructure\QuickBooks\QuickBooksCurlHttpClient;
use App\Infrastructure\QuickBooks\QuickBooksDataServiceFactory;
use App\Infrastructure\QuickBooks\QuickBooksDocumentBatchCreator;
use App\Infrastructure\QuickBooks\QuickBooksFaultMapper;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * Probes the batch-create assumptions ADR-0019 rests on against the sandbox realm in `.env`; run only through
 * `make test-quickbooks-sandbox`. Each probe prints what it measured. Refs are discovered at runtime, so a realm lacking
 * one fails naming it instead of posting a half-built document.
 */
#[CoversClass(QuickBooksDocumentBatchCreator::class)]
#[CoversClass(QuickBooksTransport::class)]
#[Group('integration')]
#[Group('quickbooks-sandbox')]
final class QuickBooksSandboxBatchTest extends TestCase
{
    private const int DEADLINE_SECONDS = 120;

    private const float MAX_FULL_BATCH_SECONDS = 12.0;

    private const int QBO_BATCH_MAXIMUM = 30;

    private const int RATE_PROBE_CALLS = 40;

    private const int TOO_MANY_REQUESTS = 429;

    private const string UNKNOWN_ITEM_ID = '999999999';

    private const string PO_NUMBER_FIELD_NAME = 'P.O. Number';

    private const string TRANSACTION_ID_FIELD_NAME = 'Transaction ID';

    private QuickBooksTransport $transport;

    private ApiKeyToken $accessToken;

    /** @var array{refs: ResolvedDocumentRefsDTO, itemName: string}|null */
    private ?array $discovered = null;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();

        if (\getenv('QUICKBOOKS_SANDBOX_RUN') !== '1') {
            self::markTestSkipped('QuickBooks sandbox tests run only through make test-quickbooks-sandbox');
        }

        // intentional: checked before any QuickBooks service is built, so a production realm is never called
        if (\config('quickbooks.environment') !== 'sandbox') {
            self::fail('Refusing to run: QUICKBOOKS_ENVIRONMENT is not sandbox');
        }

        // intentional: the OAuth token refresh goes through the Http client
        Http::allowStrayRequests();

        $this->accessToken = $this->app->make(QuickBooksTokenService::class)->accessToken();
        $this->transport = $this->app->make(QuickBooksTransport::class);
    }

    #[Test]
    public function a_per_item_fault_parses_while_its_siblings_are_created(): void
    {
        $refs = $this->refs();
        $bad = $this->receiptDocument($this->refsWithItemId(self::UNKNOWN_ITEM_ID));

        $outcomes = $this->creator()->create($this->accessToken, [$this->receiptDocument($refs), $bad, $this->receiptDocument($refs)], self::deadline());

        self::report('fault probe', \array_map(self::describe(...), $outcomes));
        $created = \array_filter($outcomes, static fn(BatchItemOutcomeResult $o): bool => $o->kind === BatchOutcomeKind::Created);
        self::assertNotSame([], $created, 'OVERTURN: one bad item failed its whole batch');
        self::assertSame(BatchOutcomeKind::Created, $outcomes[0]->kind);
        self::assertSame(BatchOutcomeKind::Faulted, $outcomes[1]->kind);
        self::assertNotNull($outcomes[1]->fault);
        self::assertSame(BatchOutcomeKind::Created, $outcomes[2]->kind);
    }

    #[Test]
    public function an_invoice_with_a_shipping_item_line_posts(): void
    {
        $outcomes = $this->creator()->create($this->accessToken, [$this->invoiceDocument($this->refs())], self::deadline());

        self::report('shipping probe', \array_map(self::describe(...), $outcomes));
        self::assertSame(BatchOutcomeKind::Created, $outcomes[0]->kind, 'STOP (D27): QBO rejected the SHIPPING_ITEM_ID line');
    }

    #[Test]
    public function a_full_batch_of_twenty_is_accepted_within_the_latency_budget(): void
    {
        $refs = $this->refs();
        $documents = [];
        for ($i = 0; $i < QuickBooksDocumentBatchCreator::MAX_BATCH_ITEMS; ++$i) {
            $documents[] = $i % 2 === 0 ? $this->invoiceDocument($refs) : $this->receiptDocument($refs);
        }

        $started = \hrtime(true);
        $outcomes = $this->creator()->create($this->accessToken, $documents, self::deadline());
        $seconds = (\hrtime(true) - $started) / 1e9;

        $kinds = \array_count_values(\array_map(static fn(BatchItemOutcomeResult $o): string => $o->kind->value, $outcomes));
        self::report('full batch probe', ['items' => \count($documents), 'seconds' => \round($seconds, 2), 'outcomes' => $kinds]);
        self::assertSame([BatchOutcomeKind::Created->value => QuickBooksDocumentBatchCreator::MAX_BATCH_ITEMS], $kinds, 'OVERTURN: QBO did not accept a full batch');
        self::assertLessThan(self::MAX_FULL_BATCH_SECONDS, $seconds, 'STOP: a full batch took 12 s or more');
    }

    /**
     * Goes through the transport, not the creator, because the chunk cap sits below QBO's batch maximum (ADR-0019).
     */
    #[Test]
    public function qbo_accepts_its_documented_batch_maximum_above_the_chunk_size(): void
    {
        $refs = $this->refs();
        $entities = [];
        for ($i = 0; $i < self::QBO_BATCH_MAXIMUM; ++$i) {
            $document = $i % 2 === 0 ? $this->invoiceDocument($refs) : $this->receiptDocument($refs);
            $entities[] = $document->draft instanceof InvoiceDraftDTO
                ? QuickBooksInvoiceMapper::toEntity($document->draft, $document->refs)
                : QuickBooksSalesReceiptMapper::toEntity($document->draft, $document->refs);
        }

        $started = \hrtime(true);
        $execution = $this->transport->executeBatch($this->accessToken, $entities, self::deadline());
        $seconds = (\hrtime(true) - $started) / 1e9;

        $kinds = [];
        foreach ($entities as $index => $entity) {
            $kinds[] = $execution->outcomeFor((string) ($index + 1), $entity::class)->kind->value;
        }

        $counts = \array_count_values($kinds);
        self::report('batch maximum probe', ['items' => self::QBO_BATCH_MAXIMUM, 'seconds' => \round($seconds, 2), 'outcomes' => $counts]);
        self::assertArrayNotHasKey(BatchOutcomeKind::Faulted->value, $counts, 'OVERTURN: QBO caps a batch below its documented maximum');

        // intentional: a curl timeout leaves every item ambiguous, which says nothing about QBO's cap
        if ($counts === [BatchOutcomeKind::Ambiguous->value => self::QBO_BATCH_MAXIMUM] && $seconds >= QuickBooksCurlHttpClient::TIMEOUT_SECONDS) {
            self::markTestIncomplete(\sprintf('LATENCY, not an overturn: the %d-item batch took %.2f s, at or past the %d s curl timeout', self::QBO_BATCH_MAXIMUM, $seconds, QuickBooksCurlHttpClient::TIMEOUT_SECONDS));
        }

        self::assertSame([BatchOutcomeKind::Created->value => self::QBO_BATCH_MAXIMUM], $counts, 'QBO did not create every item of its documented batch maximum');
    }

    /**
     * Sends raw SDK batch calls without the limiter: the transport retries a 429 silently, which would hide it here.
     */
    #[Test]
    public function forty_one_document_batch_calls_in_a_minute_draw_no_429(): void
    {
        $entity = QuickBooksSalesReceiptMapper::toEntity($this->receiptDraft(), $this->refs());
        $factory = $this->app->make(QuickBooksDataServiceFactory::class);
        $statuses = [];

        $started = \hrtime(true);
        for ($i = 0; $i < self::RATE_PROBE_CALLS; ++$i) {
            $entity->DocNumber = self::docNumber();
            $session = $factory->create($this->accessToken);
            $batch = $session->dataService->CreateNewBatch();
            $batch->AddEntity($entity, '1', 'create');
            $batch->Execute();
            $error = QuickBooksFaultMapper::faultOrNull($batch->getLastError());
            $statuses[] = $error === null ? 200 : (QuickBooksFaultMapper::status($error) ?? 0);
        }

        $seconds = (\hrtime(true) - $started) / 1e9;

        $counts = \array_count_values($statuses);
        self::report('rate probe', ['calls' => self::RATE_PROBE_CALLS, 'seconds' => \round($seconds, 2), 'statuses' => $counts]);
        self::assertArrayNotHasKey(self::TOO_MANY_REQUESTS, $counts, 'OVERTURN: 40 batch calls a minute drew a 429');
        self::assertSame([200 => self::RATE_PROBE_CALLS], $counts);
    }

    private function creator(): QuickBooksDocumentBatchCreator
    {
        return $this->app->make(QuickBooksDocumentBatchCreator::class);
    }

    private function receiptDocument(ResolvedDocumentRefsDTO $refs): PostableDocumentDTO
    {
        return new PostableDocumentDTO($this->receiptDraft(), $refs);
    }

    private function invoiceDocument(ResolvedDocumentRefsDTO $refs): PostableDocumentDTO
    {
        return new PostableDocumentDTO(new InvoiceDraftDTO($this->core(true), 'PO-PROBE', 'Probe Courier', "Probe Customer\n1 Probe St\nLeeds", ''), $refs);
    }

    private function receiptDraft(): SalesReceiptDraftDTO
    {
        return new SalesReceiptDraftDTO($this->core(false), 'PAY-PROBE', self::address(), self::address(), 'TXN-PROBE', PaymentKey::PayPal);
    }

    private function core(bool $shipping): DocumentCoreDTO
    {
        $sku = Sku::fromString($this->discovery()['itemName']);

        return new DocumentCoreDTO(
            docNumber: self::docNumber(),
            txnDate: new DateTimeImmutable(),
            billEmail: 'probe@example.test',
            customer: new CustomerDraftDTO('Probe Customer', 'Probe', 'Customer', '', 'probe@example.test', '', self::address()),
            goodsLines: [new GoodsLineDraftDTO($sku, 'Batch probe', DecimalMoney::fromString('10.00'), 1, DecimalMoney::fromString('10.00'), TaxKey::Standard)],
            shippingLine: $shipping ? new ShippingLineDraftDTO(DecimalMoney::fromString('4.99'), TaxKey::Standard) : null,
        );
    }

    private function refs(): ResolvedDocumentRefsDTO
    {
        return $this->discovery()['refs'];
    }

    /**
     * @param non-empty-string $itemId
     */
    private function refsWithItemId(string $itemId): ResolvedDocumentRefsDTO
    {
        $refs = $this->refs();
        $sku = Sku::fromString($this->discovery()['itemName']);

        return new ResolvedDocumentRefsDTO(
            customerId: $refs->customerId,
            items: ItemLookupResult::fromMatches([$sku], [['name' => $sku->value, 'id' => $itemId]]),
            taxCodeIds: [TaxKey::Standard->value => (string) $refs->taxCodeIdFor(TaxKey::Standard)],
            fixedRefIds: [FixedRefKey::Department->value => (string) $refs->fixedRefIdFor(FixedRefKey::Department)],
            termsId: $refs->termsId,
            paymentMethodId: $refs->paymentMethodId,
            depositAccountId: $refs->depositAccountId,
            poNumberFieldId: $refs->poNumberFieldId,
            transactionIdFieldId: $refs->transactionIdFieldId,
        );
    }

    /**
     * @return array{refs: ResolvedDocumentRefsDTO, itemName: string}
     */
    private function discovery(): array
    {
        if ($this->discovered !== null) {
            return $this->discovered;
        }

        $item = $this->first("SELECT * FROM Item WHERE Type IN ('Service', 'NonInventory') AND Active = true MAXRESULTS 1");
        $taxCodes = $this->transport->query($this->accessToken, 'SELECT * FROM TaxCode WHERE Active = true MAXRESULTS 100', self::deadline());
        $customFields = self::enabledCustomFieldIds($this->transport->query($this->accessToken, 'SELECT * FROM Preferences', self::deadline()));

        $refs = [
            'customer' => self::id($this->first('SELECT * FROM Customer WHERE Active = true MAXRESULTS 1')),
            'item' => self::id($item),
            'tax_code:standard' => self::taxCodeId($taxCodes, '20.0% S'),
            'terms' => self::id($this->first('SELECT * FROM Term MAXRESULTS 1')),
            'payment_method' => self::id($this->first('SELECT * FROM PaymentMethod MAXRESULTS 1')),
            'deposit_account' => self::id($this->first("SELECT * FROM Account WHERE AccountType = 'Bank' MAXRESULTS 1")),
            'fixed_ref:department' => self::id($this->first('SELECT * FROM Department MAXRESULTS 1')),
            'fixed_ref:class' => self::id($this->first('SELECT * FROM Class MAXRESULTS 1')),
            'po_number_field' => $customFields[self::PO_NUMBER_FIELD_NAME] ?? null,
            'transaction_id_field' => $customFields[self::TRANSACTION_ID_FIELD_NAME] ?? null,
        ];

        $missing = \array_keys(\array_filter($refs, static fn(?string $id): bool => $id === null));
        if ($missing !== []) {
            self::fail('STOP: the sandbox realm lacks ' . \implode(', ', $missing) . '; COR-304 setup creates them');
        }

        $sku = Sku::fromString(self::text($item->Name ?? null) ?? '');
        $this->discovered = [
            'itemName' => $sku->value,
            'refs' => new ResolvedDocumentRefsDTO(
                customerId: (string) $refs['customer'],
                items: ItemLookupResult::fromMatches([$sku], [['name' => $sku->value, 'id' => (string) $refs['item']]]),
                taxCodeIds: [TaxKey::Standard->value => (string) $refs['tax_code:standard']],
                fixedRefIds: [FixedRefKey::Department->value => (string) $refs['fixed_ref:department'], FixedRefKey::SalesClass->value => (string) $refs['fixed_ref:class']],
                termsId: $refs['terms'],
                paymentMethodId: $refs['payment_method'],
                depositAccountId: $refs['deposit_account'],
                poNumberFieldId: $refs['po_number_field'],
                transactionIdFieldId: $refs['transaction_id_field'],
            ),
        ];

        return $this->discovered;
    }

    private function first(string $query): ?object
    {
        $rows = $this->transport->query($this->accessToken, $query, self::deadline());
        $row = $rows[0] ?? null;

        return \is_object($row) ? $row : null;
    }

    /**
     * @param list<mixed> $taxCodes
     *
     * @return non-empty-string|null
     */
    private static function taxCodeId(array $taxCodes, string $name): ?string
    {
        $match = \array_find($taxCodes, static fn(mixed $code): bool => \is_object($code) && self::text($code->Name ?? null) === $name);

        return \is_object($match) ? self::id($match) : null;
    }

    /**
     * The DefinitionIds (legacy slot numbers) of the switched-on sales-form custom fields, keyed by field name.
     * Matched by name, not slot order: QBO assigns slots in its own order.
     *
     * @param list<mixed> $preferences
     *
     * @return array<string, non-empty-string>
     */
    private static function enabledCustomFieldIds(array $preferences): array
    {
        $slots = ['enabled' => [], 'names' => []];
        foreach ($preferences as $preference) {
            self::walk($preference, $slots);
        }

        $ids = [];
        foreach ($slots['names'] as $slot => $name) {
            if (isset($slots['enabled'][$slot])) {
                $ids[$name] = (string) $slot;
            }
        }

        return $ids;
    }

    /**
     * @param array{enabled: array<non-empty-string, true>, names: array<non-empty-string, non-empty-string>} $slots
     */
    private static function walk(mixed $node, array &$slots): void
    {
        if (\is_object($node)) {
            self::collectSlot($node, $slots);
            $node = \get_object_vars($node);
        }

        if (\is_array($node)) {
            foreach ($node as $child) {
                self::walk($child, $slots);
            }
        }
    }

    /**
     * @param array{enabled: array<non-empty-string, true>, names: array<non-empty-string, non-empty-string>} $slots
     */
    private static function collectSlot(object $node, array &$slots): void
    {
        $name = self::text($node->Name ?? null);
        if ($name === null || \preg_match('/SalesCustom(Name)?([1-9])$/', $name, $m) !== 1) {
            return;
        }

        $boolean = self::text($node->BooleanValue ?? null);
        $string = self::text($node->StringValue ?? null);
        self::report('custom field slot', [$name => $boolean ?? $string]);

        if ($m[1] === '' && $boolean === 'true') {
            $slots['enabled'][$m[2]] = true;
        }

        if ($m[1] !== '' && $string !== null) {
            $slots['names'][$m[2]] = $string;
        }
    }

    /**
     * @return non-empty-string|null
     */
    private static function id(?object $row): ?string
    {
        return $row === null ? null : self::text($row->Id ?? null);
    }

    /**
     * @return non-empty-string|null
     */
    private static function text(mixed $value): ?string
    {
        if (\is_object($value) && \property_exists($value, 'value')) {
            $value = $value->value;
        }

        if (!\is_string($value) && !\is_int($value)) {
            return null;
        }

        $text = \mb_trim((string) $value);

        return $text === '' ? null : $text;
    }

    /**
     * @return array<string, string|null>
     */
    private static function describe(BatchItemOutcomeResult $outcome): array
    {
        return ['kind' => $outcome->kind->value, 'code' => $outcome->fault?->code, 'message' => $outcome->fault?->message];
    }

    /**
     * @param array<mixed> $measured
     */
    private static function report(string $probe, array $measured): void
    {
        \fwrite(\STDERR, "\n[{$probe}] " . \json_encode($measured, \JSON_UNESCAPED_SLASHES) . "\n");
    }

    private static function address(): StructuredAddressDTO
    {
        return StructuredAddressDTO::fromContact(new OrderContactDTO('', '', '1 Probe St', '', '', 'Leeds', 'LS1 1AA', 'GB'));
    }

    private static function docNumber(): string
    {
        // intentional: the trailing letter keeps probe numbers off the synced orders' A<digits> shape
        return 'PRB-' . Str::upper(Str::random(12)) . '-P';
    }

    private static function deadline(): Deadline
    {
        return Deadline::after(new DateTimeImmutable(), self::DEADLINE_SECONDS);
    }
}
