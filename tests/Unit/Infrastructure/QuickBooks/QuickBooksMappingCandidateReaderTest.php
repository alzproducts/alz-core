<?php

declare(strict_types=1);

namespace Tests\Unit\Infrastructure\QuickBooks;

use App\Application\QuickBooks\Mappings\DTOs\MappingCandidateDTO;
use App\Application\QuickBooks\OrderSync\Enums\MappingEntity;
use App\Domain\Access\ValueObjects\ApiKeyToken;
use App\Domain\Exceptions\Api\ExternalServiceUnavailableException;
use App\Domain\Exceptions\Api\UnexpectedApiResultException;
use App\Domain\QuickBooks\Exceptions\QuickBooksDeadlineExceededException;
use App\Infrastructure\QuickBooks\Mappers\QuickBooksMappingEntityMapper;
use App\Infrastructure\QuickBooks\QuickBooksConfig;
use App\Infrastructure\QuickBooks\QuickBooksEnvironment;
use App\Infrastructure\QuickBooks\QuickBooksMappingCandidateReader;
use App\Infrastructure\QuickBooks\QuickBooksTransport;
use InvalidArgumentException;
use LogicException;
use Mockery;
use Mockery\Adapter\Phpunit\MockeryPHPUnitIntegration;
use Mockery\MockInterface;
use Override;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use QuickBooksOnline\API\Data\IPPAccount;
use QuickBooksOnline\API\Data\IPPClass;
use QuickBooksOnline\API\Data\IPPDepartment;
use QuickBooksOnline\API\Data\IPPIntuitEntity;
use QuickBooksOnline\API\Data\IPPPaymentMethod;
use QuickBooksOnline\API\Data\IPPPreferences;
use QuickBooksOnline\API\Data\IPPTaxCode;
use QuickBooksOnline\API\Data\IPPTerm;
use stdClass;

#[CoversClass(QuickBooksMappingCandidateReader::class)]
#[CoversClass(QuickBooksMappingEntityMapper::class)]
final class QuickBooksMappingCandidateReaderTest extends TestCase
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
    public function every_page_is_read_with_inactive_rows_marked(): void
    {
        $rows = \array_map(
            static fn(int $n): IPPTaxCode => self::row(new IPPTaxCode(), ['Id' => (string) $n, 'Name' => "Code {$n}", 'Active' => $n === 1001 ? 'false' : 'true']),
            \range(1, 1001),
        );
        $this->transport->shouldReceive('query')->once()
            ->with(Mockery::any(), 'SELECT * FROM TaxCode WHERE Active IN (true, false) STARTPOSITION 1 MAXRESULTS 1000', Mockery::any())
            ->andReturn(\array_slice($rows, 0, 1000));
        $this->transport->shouldReceive('query')->once()
            ->with(Mockery::any(), 'SELECT * FROM TaxCode WHERE Active IN (true, false) STARTPOSITION 1001 MAXRESULTS 1000', Mockery::any())
            ->andReturn(\array_slice($rows, 1000));

        $candidates = $this->candidates(MappingEntity::TaxCode);

        $this->assertCount(1001, $candidates);
        $this->assertTrue($candidates[999]->active);
        $this->assertEquals(new MappingCandidateDTO('1001', 'Code 1001', false), $candidates[1000]);
    }

    #[Test]
    public function an_account_takes_its_full_name_and_type(): void
    {
        $this->transport->shouldReceive('query')->once()->andReturn([
            self::row(new IPPAccount(), ['Id' => 35, 'Name' => 'PayPal', 'FullyQualifiedName' => 'Cash:PayPal', 'AccountType' => 'Bank', 'Active' => false]),
            self::row(new IPPAccount(), ['Id' => '36', 'Name' => 'Opayo', 'FullyQualifiedName' => 'Opayo', 'AccountType' => (object) ['value' => 'Bank'], 'Active' => 'true']),
        ]);

        $this->assertEquals(
            [new MappingCandidateDTO('35', 'Cash:PayPal', false, 'Bank'), new MappingCandidateDTO('36', 'Opayo', true, 'Bank')],
            $this->candidates(MappingEntity::Account),
        );
    }

    #[Test]
    public function a_row_without_an_id_is_unexpected(): void
    {
        $this->transport->shouldReceive('query')->once()->andReturn([self::row(new IPPTerm(), ['Name' => 'Net 30'])]);

        $this->expectException(UnexpectedApiResultException::class);

        $this->candidates(MappingEntity::Term);
    }

    #[Test]
    public function custom_fields_take_id_and_label_from_the_slot_digit(): void
    {
        $this->transport->shouldReceive('query')->once()
            ->with(Mockery::any(), 'SELECT * FROM Preferences', Mockery::any())
            ->andReturn([self::preferences([
                self::group([self::entry('SalesCustomName3', 'StringValue', 'Transaction ID'), self::entry('SalesCustomName1', 'StringValue', 'P.O. Number')]),
                self::group([
                    self::entry('UseSalesCustom3', 'BooleanValue', 'true'),
                    self::entry('UseSalesCustom1', 'BooleanValue', 'true'),
                    self::entry('UseSalesCustom2', 'BooleanValue', 'false'),
                ]),
            ])]);

        $this->assertEquals(
            [new MappingCandidateDTO('1', 'P.O. Number', true), new MappingCandidateDTO('2', '', false), new MappingCandidateDTO('3', 'Transaction ID', true)],
            $this->candidates(MappingEntity::CustomField),
        );
    }

    #[Test]
    public function a_company_with_no_slots_has_no_custom_field_candidates(): void
    {
        $this->transport->shouldReceive('query')->once()->andReturn([self::preferences([])]);

        $this->assertSame([], $this->candidates(MappingEntity::CustomField));
    }

    /**
     * @return array<string, array{list<object>}>
     */
    public static function preferencesWithoutSalesForms(): array
    {
        return ['no row' => [[]], 'no section' => [[new IPPPreferences()]]];
    }

    /**
     * @param list<object> $rows
     */
    #[Test]
    #[DataProvider('preferencesWithoutSalesForms')]
    public function preferences_without_a_sales_form_section_are_unexpected(array $rows): void
    {
        $this->transport->shouldReceive('query')->once()->andReturn($rows);

        $this->expectException(UnexpectedApiResultException::class);

        $this->candidates(MappingEntity::CustomField);
    }

    #[Test]
    public function an_expired_deadline_is_an_unavailable_service(): void
    {
        $this->transport->shouldReceive('query')->once()->andThrow(new QuickBooksDeadlineExceededException(-1.0));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->candidates(MappingEntity::Account);
    }

    #[Test]
    public function an_expired_deadline_on_a_create_is_an_unavailable_service(): void
    {
        $this->transport->shouldReceive('add')->once()->andThrow(new QuickBooksDeadlineExceededException(-1.0));

        $this->expectException(ExternalServiceUnavailableException::class);

        $this->create(MappingEntity::Account);
    }

    /**
     * @return array<string, array{MappingEntity, class-string<IPPIntuitEntity>, array<string, int|string>}>
     */
    public static function creatableEntities(): array
    {
        return [
            'account' => [MappingEntity::Account, IPPAccount::class, ['AccountType' => 'Bank']],
            'term' => [MappingEntity::Term, IPPTerm::class, ['DueDays' => 30]],
            'payment method' => [MappingEntity::PaymentMethod, IPPPaymentMethod::class, []],
            'department' => [MappingEntity::Department, IPPDepartment::class, []],
            'class' => [MappingEntity::SalesClass, IPPClass::class, []],
        ];
    }

    /**
     * @param class-string<IPPIntuitEntity> $class
     * @param array<string, int|string>     $extra
     */
    #[Test]
    #[DataProvider('creatableEntities')]
    public function a_create_sends_the_name_and_the_fields_qbo_requires(MappingEntity $entity, string $class, array $extra): void
    {
        $sent = null;
        $this->transport->shouldReceive('add')->once()
            ->with(Mockery::any(), Mockery::type($class), $class, Mockery::any())
            ->andReturnUsing(static function (ApiKeyToken $token, IPPIntuitEntity $payload) use (&$sent, $class): object {
                $sent = \array_filter(\get_object_vars($payload), static fn(mixed $value): bool => $value !== null);

                $name = \property_exists($class, 'FullyQualifiedName') ? 'FullyQualifiedName' : 'Name';

                return self::row(new $class(), ['Id' => '77', $name => 'Sync Test', 'Active' => 'true']);
            });

        $created = $this->create($entity);

        $this->assertEquals(new MappingCandidateDTO('77', 'Sync Test', true), $created);
        $this->assertIsArray($sent);
        $this->assertEquals(['Name' => 'Sync Test', ...$extra], \array_map(
            static fn(mixed $value): mixed => \is_object($value) ? \get_object_vars($value)['value'] ?? $value : $value,
            $sent,
        ));
    }

    /**
     * @return array<string, array{MappingEntity}>
     */
    public static function neverCreated(): array
    {
        return ['tax code' => [MappingEntity::TaxCode], 'custom field' => [MappingEntity::CustomField]];
    }

    #[Test]
    #[DataProvider('neverCreated')]
    public function tax_codes_and_custom_fields_are_never_created(MappingEntity $entity): void
    {
        $this->transport->shouldNotReceive('add');

        $this->expectException(InvalidArgumentException::class);

        $this->create($entity);
    }

    #[Test]
    #[DataProvider('neverCreated')]
    public function the_mapper_refuses_entities_that_are_never_created(MappingEntity $entity): void
    {
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('This entity is never created');

        QuickBooksMappingEntityMapper::toEntity($entity, 'Sync Test');
    }

    /**
     * @return list<MappingCandidateDTO>
     */
    private function candidates(MappingEntity $entity): array
    {
        return $this->reader()->candidates(new ApiKeyToken('access'), $entity);
    }

    private function create(MappingEntity $entity): MappingCandidateDTO
    {
        return $this->reader()->create(new ApiKeyToken('access'), $entity, 'Sync Test');
    }

    private function reader(): QuickBooksMappingCandidateReader
    {
        $config = new QuickBooksConfig(QuickBooksEnvironment::Sandbox, 'id', 'secret', 'https://example.test/callback', '9130');

        return new QuickBooksMappingCandidateReader($this->transport, $config, new NullLogger());
    }

    /**
     * @template T of object
     *
     * @param T                    $row
     * @param array<string, mixed> $fields
     *
     * @return T
     */
    private static function row(object $row, array $fields): object
    {
        foreach ($fields as $name => $value) {
            $row->{$name} = $value;
        }

        return $row;
    }

    /**
     * @param list<object> $groups
     */
    private static function preferences(array $groups): IPPPreferences
    {
        return self::row(new IPPPreferences(), ['SalesFormsPrefs' => (object) ['CustomField' => $groups]]);
    }

    /**
     * @param list<object> $entries
     */
    private static function group(array $entries): object
    {
        return (object) ['CustomField' => $entries];
    }

    private static function entry(string $name, string $valueField, string $value): stdClass
    {
        return (object) ['Name' => "SalesFormsPrefs.{$name}", $valueField => $value];
    }
}
