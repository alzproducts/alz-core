<?php

declare(strict_types=1);

namespace Tests\Integration\QuickBooks;

use App\Application\QuickBooks\Services\QuickBooksTokenService;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\QuickBooks\Exceptions\QuickBooksFaultException;
use App\Domain\Shared\ValueObjects\Deadline;
use App\Infrastructure\QuickBooks\QuickBooksCall;
use App\Infrastructure\QuickBooks\QuickBooksCallSpec;
use App\Infrastructure\QuickBooks\QuickBooksQueryEscaper;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use DateTimeImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use QuickBooksOnline\API\Data\IPPCustomer;
use QuickBooksOnline\API\Data\IPPItem;
use Tests\TestCase;

/**
 * Real calls to the QuickBooks sandbox realm in `.env`; run only through `make test-quickbooks-sandbox`.
 * No database transaction: a token refresh rotates the stored refresh token, and a rollback would discard it.
 */
#[CoversClass(QuickBooksTransport::class)]
#[CoversClass(QuickBooksQueryEscaper::class)]
#[Group('integration')]
#[Group('quickbooks-sandbox')]
final class QuickBooksSandboxTest extends TestCase
{
    private const int DEADLINE_SECONDS = 60;

    private QuickBooksTransport $transport;

    private ApiKeyToken $accessToken;

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
    public function in_on_customer_display_name_returns_exactly_the_named_customers(): void
    {
        $known = $this->customerNames('SELECT * FROM Customer WHERE Active IN (true, false) MAXRESULTS 3');
        self::assertNotEmpty($known, 'The sandbox company has no customers to probe with');

        $found = $this->customerNames($this->customerInQuery([...$known, self::missingName()]));

        self::assertEqualsCanonicalizing($known, $found);
    }

    #[Test]
    public function in_on_customer_display_name_matches_ignoring_case(): void
    {
        $known = $this->customerNames('SELECT * FROM Customer WHERE Active IN (true, false) MAXRESULTS 3');
        self::assertNotEmpty($known, 'The sandbox company has no customers to probe with');

        $found = $this->customerNames($this->customerInQuery(\array_map(\mb_strtoupper(...), $known)));

        self::assertEqualsCanonicalizing($known, $found);
    }

    #[Test]
    public function in_on_item_name_returns_exactly_the_named_items(): void
    {
        $known = $this->itemNames('SELECT * FROM Item MAXRESULTS 3');
        self::assertNotEmpty($known, 'The sandbox company has no items to probe with');

        $found = $this->itemNames('SELECT * FROM Item WHERE Name IN '
            . QuickBooksQueryEscaper::inList([...$known, self::missingName()])
            . ' MAXRESULTS ' . QuickBooksQueryEscaper::MAX_RESULTS);

        self::assertEqualsCanonicalizing($known, $found);
    }

    #[Test]
    public function in_with_no_match_returns_zero_rows(): void
    {
        $rows = $this->transport->query(
            $this->accessToken,
            'SELECT * FROM Item WHERE Name IN ' . QuickBooksQueryEscaper::inList([self::missingName()]),
            self::otherCall(),
        );

        self::assertSame([], $rows);
    }

    #[Test]
    public function in_on_an_unknown_field_raises_a_fault_rather_than_zero_rows(): void
    {
        $this->expectException(QuickBooksFaultException::class);

        $this->transport->query(
            $this->accessToken,
            'SELECT * FROM Item WHERE NoSuchField IN ' . QuickBooksQueryEscaper::inList([self::missingName()]),
            self::otherCall(),
        );
    }

    /**
     * @param non-empty-list<string> $names
     */
    private function customerInQuery(array $names): string
    {
        return 'SELECT * FROM Customer WHERE Active IN (true, false) AND DisplayName IN '
            . QuickBooksQueryEscaper::inList($names)
            . ' MAXRESULTS ' . QuickBooksQueryEscaper::MAX_RESULTS;
    }

    /**
     * @return list<string>
     */
    private function customerNames(string $query): array
    {
        $names = [];
        foreach ($this->transport->query($this->accessToken, $query, self::otherCall()) as $row) {
            self::assertInstanceOf(IPPCustomer::class, $row);
            $names[] = (string) $row->DisplayName;
        }

        return $names;
    }

    /**
     * @return list<string>
     */
    private function itemNames(string $query): array
    {
        $names = [];
        foreach ($this->transport->query($this->accessToken, $query, self::otherCall()) as $row) {
            self::assertInstanceOf(IPPItem::class, $row);
            $names[] = (string) $row->Name;
        }

        return $names;
    }

    /**
     * Carries a quote and a backslash, so every probe also sends escaped literals.
     */
    private static function missingName(): string
    {
        return "zz-no-such-name O'Brien\\" . Str::random(8);
    }

    private static function otherCall(): QuickBooksCallSpec
    {
        return new QuickBooksCallSpec(QuickBooksCall::Other, Deadline::after(new DateTimeImmutable(), self::DEADLINE_SECONDS));
    }
}
