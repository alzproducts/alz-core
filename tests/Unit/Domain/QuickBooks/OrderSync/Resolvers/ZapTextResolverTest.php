<?php

declare(strict_types=1);

namespace Tests\Unit\Domain\QuickBooks\OrderSync\Resolvers;

use App\Domain\QuickBooks\OrderSync\Resolvers\ZapTextResolver;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ZapTextResolver::class)]
final class ZapTextResolverTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function invalidChars(): iterable
    {
        yield 'no invalid chars' => ['Acme Ltd', 'Acme Ltd'];
        yield 'first colon only' => ['A:B:C', 'AB:C'];
        yield 'first semicolon only' => ['A;B;C', 'AB;C'];
        yield 'first of each' => ['A:B;C:D;E', 'ABC:D;E'];
        yield 'empty' => ['', ''];
        yield 'multibyte before the colon' => ['Café: Bar', 'Café Bar'];
        yield 'multibyte before the semicolon' => ['Zoë Smith; Sons', 'Zoë Smith Sons'];
    }

    #[Test]
    #[DataProvider('invalidChars')]
    public function it_removes_only_the_first_colon_and_semicolon(string $value, string $expected): void
    {
        self::assertSame($expected, ZapTextResolver::removeInvalidChars($value));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function poNumbers(): iterable
    {
        yield 'empty comments' => ['', ''];
        yield 'no reference' => ['Please leave by the door', ''];
        yield 'contains *>' => ['*> Reference PO123', ''];
        yield 'reference, any case' => ['our reference PO123', 'PO123'];
        yield 'only the first space goes' => ['Reference PO 123', 'PO 123'];
        yield 'stops at the next reference' => ['Reference PO1 reference PO2', 'PO1 '];
        yield 'nothing after reference' => ['Reference', ''];
        yield 'upper-cases the text' => ['Reference po-12a', 'PO-12A'];
        yield 'multibyte before the first space' => ['Reference éPO 1', 'ÉPO 1'];
    }

    #[Test]
    #[DataProvider('poNumbers')]
    public function it_extracts_the_po_number_as_the_zap_does(string $comments, string $expected): void
    {
        self::assertSame($expected, ZapTextResolver::poNumber($comments));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function shipVias(): iterable
    {
        yield 'plain method' => ['Royal Mail', 'Royal Mail'];
        yield 'cut before the first parenthesis, then trimmed' => ['Next Day (order by 2pm) (UK)', 'Next Day'];
        yield 'first colon and semicolon removed' => ['DPD: Express; Saturday', 'DPD Express Saturday'];
        yield 'truncated to 31 characters' => [\str_repeat('x', 40), \str_repeat('x', 31)];
        yield 'trimmed before truncating' => ['  Courier  ', 'Courier'];
    }

    #[Test]
    #[DataProvider('shipVias')]
    public function it_builds_ship_via_as_the_zap_does(string $method, string $expected): void
    {
        self::assertSame($expected, ZapTextResolver::shipVia($method));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function transactionIds(): iterable
    {
        yield 'empty' => ['', ''];
        yield 'plain id' => ['pi_3Nabc', 'pi_3Nabc'];
        yield 'templated placeholder' => ['{{transaction_id}}', ''];
    }

    #[Test]
    #[DataProvider('transactionIds')]
    public function it_blanks_a_templated_transaction_id(string $id, string $expected): void
    {
        self::assertSame($expected, ZapTextResolver::transactionId($id));
    }
}
