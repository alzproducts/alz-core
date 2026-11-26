<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Application\QuickBooks\OrderSync\DTOs\CustomerDraftDTO;
use App\Application\QuickBooks\OrderSync\DTOs\OrderContactDTO;
use App\Application\QuickBooks\OrderSync\DTOs\StructuredAddressDTO;
use App\Application\QuickBooks\OrderSync\Enums\CustomerFailureKind;
use App\Application\QuickBooks\OrderSync\Results\CustomerResolutionResult;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Enums\QuickBooksFaultKind;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\QuickBooks\ValueObjects\QuickBooksFault;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksCall;
use App\Infrastructure\QuickBooks\QuickBooksCallSpec;
use App\Infrastructure\QuickBooks\QuickBooksCustomerProvisioner;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use DateTimeImmutable;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Data\IPPItem;
use QuickBooksOnline\API\Data\IPPReferenceType;

#[CoversClass(QuickBooksCustomerProvisioner::class)]
final class QuickBooksCustomerProvisionerTest extends TestCase
{
    use MockeryPHPUnitIntegration;

    private QuickBooksTransport&MockInterface $transport;

    #[Override]
    protected function setUp(): void
    {
        parent::setUp();
        $this->transport = Mockery::mock(QuickBooksTransport::class);
    }

    #[Test]
    public function an_empty_list_makes_no_call(): void
    {
        $this->transport->shouldNotReceive('query');
        $this->transport->shouldNotReceive('add');

        $this->assertNull($this->provision([])->forDisplayName('Anyone'));
    }

    #[Test]
    public function it_queries_active_and_inactive_customers_with_escaped_names(): void
    {
        $this->transport->shouldReceive('query')->once()
            ->with(Mockery::any(), "SELECT * FROM Customer WHERE Active IN (true, false) AND DisplayName IN ('O\\'Brien', 'Jo Bloggs') MAXRESULTS 1000", Mockery::any())
            ->andReturn([self::customer("O'Brien", '1'), self::customer('jo bloggs', '2', salesTermRef: '3')]);
        $this->transport->shouldNotReceive('add');

        $result = $this->provision([self::draft("O'Brien"), self::draft('Jo Bloggs'), self::draft('JO BLOGGS')]);

        $this->assertSame('1', $result->forDisplayName("O'Brien")?->match?->id);
        $match = $result->forDisplayName('Jo Bloggs')?->match;
        $this->assertSame('2', $match?->id);
        $this->assertSame('jo bloggs', $match?->displayName);
        $this->assertSame('3', $match?->salesTermRef);
    }

    #[Test]
    public function more_than_a_hundred_names_query_in_two_chunks_before_any_create(): void
    {
        $names = \array_map(static fn(int $n): string => "Customer {$n}", \range(1, 101));
        $order = [];
        $this->transport->shouldReceive('query')->twice()->andReturnUsing(static function (ApiKeyToken $token, string $query) use (&$order): array {
            $order[] = \mb_substr_count($query, "'Customer ") . ' names';

            return [];
        });
        $this->transport->shouldReceive('add')->times(101)->andReturnUsing(static function (ApiKeyToken $token, IPPCustomer $draft) use (&$order): IPPCustomer {
            $order[] = 'add';

            return self::customer((string) $draft->DisplayName, '9');
        });

        $result = $this->provision(\array_map(self::draft(...), $names));

        $this->assertSame(['100 names', '1 names'], \array_slice($order, 0, 2));
        $this->assertSame('9', $result->forDisplayName('Customer 101')?->match?->id);
    }

    #[Test]
    public function a_miss_is_created_once(): void
    {
        $this->transport->shouldReceive('query')->once()->andReturn([]);
        $this->transport->shouldReceive('add')->once()
            ->with(Mockery::any(), Mockery::on(static fn(IPPCustomer $c): bool => $c->DisplayName === 'Jo Bloggs'), IPPCustomer::class, Mockery::any())
            ->andReturn(self::customer('Jo Bloggs', '42'));

        $this->assertSame('42', $this->provision([self::draft('Jo Bloggs')])->forDisplayName('Jo Bloggs')?->match?->id);
    }

    #[Test]
    public function drafts_folding_to_one_name_create_once_from_the_first(): void
    {
        $this->transport->shouldReceive('query')->once()
            ->with(Mockery::any(), "SELECT * FROM Customer WHERE Active IN (true, false) AND DisplayName IN ('Jo Bloggs') MAXRESULTS 1000", Mockery::any())
            ->andReturn([]);
        $this->transport->shouldReceive('add')->once()
            ->with(Mockery::any(), Mockery::on(static fn(IPPCustomer $c): bool => $c->DisplayName === 'Jo Bloggs'), IPPCustomer::class, Mockery::any())
            ->andReturn(self::customer('Jo Bloggs', '42'));

        $result = $this->provision([self::draft('Jo Bloggs'), self::draft('JO BLOGGS')]);

        $this->assertSame('42', $result->forDisplayName('JO BLOGGS')?->match?->id);
    }

    #[Test]
    public function a_name_with_edge_spaces_queries_trimmed_and_matches_its_row(): void
    {
        $this->transport->shouldReceive('query')->once()
            ->with(Mockery::any(), "SELECT * FROM Customer WHERE Active IN (true, false) AND DisplayName IN ('Jo Bloggs') MAXRESULTS 1000", Mockery::any())
            ->andReturn([self::customer('Jo Bloggs', '3')]);
        $this->transport->shouldNotReceive('add');

        $this->assertSame('3', $this->provision([self::draft(' Jo Bloggs ')])->forDisplayName(' Jo Bloggs ')?->match?->id);
    }

    #[Test]
    public function two_names_that_differ_only_by_edge_spaces_both_resolve(): void
    {
        $this->transport->shouldReceive('query')->once()->andReturn([]);
        $this->transport->shouldReceive('add')->once()->andReturn(self::customer('Jo Bloggs', '3'));

        $result = $this->provision([self::draft('Jo Bloggs'), self::draft(' Jo Bloggs ')]);

        $this->assertSame('3', $result->forDisplayName('Jo Bloggs')?->match?->id);
        $this->assertSame('3', $result->forDisplayName(' Jo Bloggs ')?->match?->id);
    }

    /**
     * @return iterable<string, array{mixed, string|null}>
     */
    public static function currencies(): iterable
    {
        yield 'GBP' => ['GBP', 'GBP'];
        yield 'USD' => ['USD', 'USD'];
        yield 'reference object' => [new IPPReferenceType(['value' => 'USD']), 'USD'];
        yield 'absent' => [null, null];
        yield 'empty text' => ['', ''];
        yield 'unreadable array' => [['USD'], ''];
        yield 'unreadable number' => [826, ''];
        yield 'unreadable object' => [new IPPItem(), ''];
        yield 'reference object without a value' => [new IPPReferenceType(), ''];
    }

    #[Test]
    #[DataProvider('currencies')]
    public function it_reports_the_currency_of_a_found_and_a_created_customer(mixed $currencyRef, ?string $expected): void
    {
        $found = self::customer('Found Ltd', '1');
        $found->CurrencyRef = $currencyRef;
        $created = self::customer('New Ltd', '2');
        $created->CurrencyRef = $currencyRef;
        $this->transport->shouldReceive('query')->once()->andReturn([$found]);
        $this->transport->shouldReceive('add')->once()->andReturn($created);

        $result = $this->provision([self::draft('Found Ltd'), self::draft('New Ltd')]);

        $this->assertNotNull($result->forDisplayName('Found Ltd')?->match);
        $this->assertNotNull($result->forDisplayName('New Ltd')?->match);
        $this->assertSame($expected, $result->forDisplayName('Found Ltd')->match->currency);
        $this->assertSame($expected, $result->forDisplayName('New Ltd')->match->currency);
    }

    #[Test]
    public function an_inactive_first_hit_is_inactive_and_creates_nothing(): void
    {
        $this->transport->shouldReceive('query')->once()->andReturn([self::customer('Jo Bloggs', '1', active: 'false'), self::customer('Jo Bloggs', '2')]);
        $this->transport->shouldNotReceive('add');

        $resolution = $this->provision([self::draft('Jo Bloggs')])->forDisplayName('Jo Bloggs');

        $this->assertSame(CustomerFailureKind::Inactive, $resolution?->failure);
        $this->assertNull($resolution?->match);
    }

    #[Test]
    public function a_duplicate_name_fault_requeries_and_uses_the_match(): void
    {
        $this->transport->shouldReceive('query')->twice()->andReturn([], [self::customer('Jo Bloggs', '7')]);
        $this->transport->shouldReceive('add')->once()->andThrow(self::faultException(QuickBooksFaultKind::DuplicateCustomerName, '6240'));

        $this->assertSame('7', $this->provision([self::draft('Jo Bloggs')])->forDisplayName('Jo Bloggs')?->match?->id);
    }

    #[Test]
    public function a_duplicate_name_fault_with_an_inactive_requery_is_inactive(): void
    {
        $this->transport->shouldReceive('query')->twice()->andReturn([], [self::customer('Jo Bloggs', '7', active: 'false')]);
        $this->transport->shouldReceive('add')->once()->andThrow(self::faultException(QuickBooksFaultKind::DuplicateCustomerName, '6240'));

        $this->assertSame(CustomerFailureKind::Inactive, $this->provision([self::draft('Jo Bloggs')])->forDisplayName('Jo Bloggs')?->failure);
    }

    #[Test]
    public function a_duplicate_name_fault_with_no_customer_is_a_name_clash(): void
    {
        $fault = self::faultException(QuickBooksFaultKind::DuplicateCustomerName, '6240');
        $this->transport->shouldReceive('query')->twice()->andReturn([], []);
        $this->transport->shouldReceive('add')->once()->andThrow($fault);

        $resolution = $this->provision([self::draft('Jo Bloggs')])->forDisplayName('Jo Bloggs');

        $this->assertSame(CustomerFailureKind::NameClash, $resolution?->failure);
        $this->assertSame($fault->fault, $resolution?->fault);
    }

    #[Test]
    public function another_create_fault_fails_only_that_name(): void
    {
        $fault = self::faultException(QuickBooksFaultKind::Validation, '6000', 'PrimaryEmailAddr', 'Invalid email');
        $this->transport->shouldReceive('query')->once()->andReturn([self::customer('Kept Ltd', '5')]);
        $this->transport->shouldReceive('add')->once()->andThrow($fault);

        $result = $this->provision([self::draft('Jo Bloggs'), self::draft('Kept Ltd')]);

        $failed = $result->forDisplayName('Jo Bloggs');
        $this->assertSame(CustomerFailureKind::Faulted, $failed?->failure);
        $this->assertSame(['6000', 'PrimaryEmailAddr', 'Invalid email'], [$failed?->fault?->code, $failed?->fault?->element, $failed?->fault?->message]);
        $this->assertSame('5', $result->forDisplayName('Kept Ltd')?->match?->id);
    }

    #[Test]
    public function a_row_that_is_not_a_customer_is_unexpected(): void
    {
        $this->transport->shouldReceive('query')->once()->andReturn([new IPPItem()]);

        $this->expectException(UnexpectedApiResultException::class);

        $this->provision([self::draft('Jo Bloggs')]);
    }

    #[Test]
    public function a_customer_without_an_id_is_unexpected(): void
    {
        $this->transport->shouldReceive('query')->once()->andReturn([self::customer('Jo Bloggs', null)]);

        $this->expectException(UnexpectedApiResultException::class);

        $this->provision([self::draft('Jo Bloggs')]);
    }

    #[Test]
    public function the_find_and_the_create_are_sent_as_their_own_calls(): void
    {
        $this->transport->shouldReceive('query')->once()
            ->with(Mockery::any(), Mockery::any(), Mockery::on(static fn(QuickBooksCallSpec $spec): bool => $spec->call === QuickBooksCall::CustomerLookup))
            ->andReturn([]);
        $this->transport->shouldReceive('add')->once()
            ->with(Mockery::any(), Mockery::any(), IPPCustomer::class, Mockery::on(static fn(QuickBooksCallSpec $spec): bool => $spec->call === QuickBooksCall::CustomerCreate))
            ->andReturn(self::customer('Jo Bloggs', '3'));

        $this->provision([self::draft('Jo Bloggs')]);
    }

    /**
     * @param list<CustomerDraftDTO> $drafts
     */
    private function provision(array $drafts): CustomerResolutionResult
    {
        return new QuickBooksCustomerProvisioner($this->transport, new NullLogger())
            ->findOrCreate(new ApiKeyToken('access'), $drafts, Deadline::after(new DateTimeImmutable(), 30));
    }

    private static function draft(string $displayName): CustomerDraftDTO
    {
        return new CustomerDraftDTO($displayName, '', '', '', '', '', StructuredAddressDTO::fromContact(new OrderContactDTO('', '', '', '', '', '', '', '')));
    }

    /**
     * Shaped as the SDK's XML parse leaves a row: `Active` and `SalesTermRef` as strings.
     */
    private static function customer(string $displayName, ?string $id, string $active = 'true', ?string $salesTermRef = null): IPPCustomer
    {
        $customer = new IPPCustomer();
        $customer->DisplayName = $displayName;
        $customer->Id = $id;
        $customer->Active = $active;
        $customer->SalesTermRef = $salesTermRef;

        return $customer;
    }

    private static function faultException(QuickBooksFaultKind $kind, string $code, string $element = 'Name', string $message = 'Rejected'): QuickBooksFaultException
    {
        return new QuickBooksFaultException('QuickBooks', new QuickBooksFault($kind, $code, $element, $message));
    }
}
