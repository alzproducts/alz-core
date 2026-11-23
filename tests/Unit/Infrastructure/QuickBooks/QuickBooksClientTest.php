<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\PostableDocumentDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use App\Application\QuickBooks\OrderSync\Enums\BatchOutcomeKind;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Application\QuickBooks\OrderSync\Results\BatchItemOutcomeResult;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Catalog\Product\ValueObjects\Sku;
use App\Domain\Exceptions\Api\AuthenticationExpiredException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksBatchExecution;
use App\Infrastructure\QuickBooks\QuickBooksClient;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksCustomerProvisioner;
use App\Infrastructure\QuickBooks\QuickBooksDocumentBatchCreator;
use App\Infrastructure\QuickBooks\QuickBooksEnvironment;
use App\Infrastructure\QuickBooks\QuickBooksMappingCandidateReader;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use DateTimeImmutable;
use LogicException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\Data\IPPClass;
use QuickBooksOnline\API\Data\IPPCompanyInfo;
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

    #[Test]
    public function candidates_come_from_the_mapping_reader(): void
    {
        $class = new IPPClass();
        $class->Id = '5';
        $class->FullyQualifiedName = 'Web';
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldReceive('query')->once()->andReturn([$class]);

        $candidates = self::client($transport)->candidates(new ApiKeyToken('access'), MappingEntity::SalesClass);

        $this->assertEquals([new MappingCandidateDTO('5', 'Web', true)], $candidates);
    }

    #[Test]
    public function create_outside_the_sandbox_throws_before_any_call(): void
    {
        $transport = Mockery::mock(QuickBooksTransport::class);
        $transport->shouldNotReceive('add');
        $transport->shouldNotReceive('query');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('Mapping entities are created in the QuickBooks sandbox only');

        self::client($transport, environment: QuickBooksEnvironment::Production)->create(new ApiKeyToken('access'), MappingEntity::Account, 'Sync Test');
    }

    private static function client(
        QuickBooksTransport $transport,
        ?QuickBooksCustomerProvisioner $provisioner = null,
        QuickBooksEnvironment $environment = QuickBooksEnvironment::Sandbox,
    ): QuickBooksClient {
        return new QuickBooksClient(
            $transport,
            $provisioner ?? new QuickBooksCustomerProvisioner($transport, new NullLogger()),
            new QuickBooksDocumentBatchCreator($transport, new NullLogger()),
            new QuickBooksMappingCandidateReader($transport, new QuickBooksConfig($environment, 'id', 'secret', 'https://example.test/callback', '9130'), new NullLogger()),
            new NullLogger(),
        );
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
