<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\QuickBooks;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

#[CoversNothing]
final class OrderSyncSchemaTest extends TestCase
{
    use DatabaseTransactions;

    private const string SEED_MIGRATION = 'database/migrations/2026_11_21_200100_seed_quickbooks_mappings.php';

    /** @var array<string, string> */
    private const array SEEDED_KEYS = [
        'tax.standard' => 'TaxCode',
        'tax.zero' => 'TaxCode',
        'tax.eu_goods' => 'TaxCode',
        'tax.eu_shipping' => 'TaxCode',
        'deposit_account.paypal' => 'Account',
        'deposit_account.card_opayo' => 'Account',
        'deposit_account.shopwired_payments' => 'Account',
        'payment_method.paypal' => 'PaymentMethod',
        'payment_method.card_opayo' => 'PaymentMethod',
        'payment_method.shopwired_payments' => 'PaymentMethod',
        'department' => 'Department',
        'class' => 'Class',
        'default_terms' => 'Term',
        'custom_field.po_number' => 'CustomField',
        'custom_field.transaction_id' => 'CustomField',
    ];

    #[Test]
    public function order_syncs_has_exactly_the_specified_columns_and_nullability(): void
    {
        self::assertSame(
            [
                'id' => 'NO',
                'realm_id' => 'NO',
                'reference' => 'NO',
                'order_external_id' => 'NO',
                'doc_type' => 'YES',
                'status' => 'NO',
                'qbo_txn_id' => 'YES',
                'qbo_doc_number' => 'YES',
                'attempts' => 'NO',
                'last_attempted_at' => 'YES',
                'last_error_code' => 'YES',
                'last_error_message' => 'YES',
                'claim_token' => 'YES',
                'lease_expires_at' => 'YES',
                'payload_hash' => 'YES',
                'synced_at' => 'YES',
                'created_at' => 'NO',
                'updated_at' => 'NO',
            ],
            self::nullability('order_syncs'),
        );
    }

    #[Test]
    public function order_syncs_is_unique_on_realm_and_reference(): void
    {
        $this->insertSync('realm-schema', 2147450101);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('order_syncs_realm_id_reference_unique');

        $this->insertSync('realm-schema', 2147450101);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rejectedSyncValues(): iterable
    {
        yield 'unknown status' => ['status', 'cancelled'];
        yield 'unknown doc type' => ['doc_type', 'credit_memo'];
    }

    #[Test]
    #[DataProvider('rejectedSyncValues')]
    public function order_syncs_checks_reject_values_outside_the_allowed_set(string $column, string $value): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('check constraint');

        $this->insertSync('realm-schema', 2147450102, [$column => $value]);
    }

    #[Test]
    public function order_syncs_checks_accept_every_allowed_status_and_doc_type(): void
    {
        foreach (['pending', 'processing', 'synced', 'failed'] as $offset => $status) {
            $this->insertSync('realm-schema', 2147450110 + $offset, [
                'status' => $status,
                'doc_type' => $offset % 2 === 0 ? 'invoice' : 'sales_receipt',
            ]);
        }

        self::assertSame(4, DB::table('quickbooks.order_syncs')->where('realm_id', 'realm-schema')->count());
    }

    #[Test]
    public function order_sync_errors_has_the_specified_columns_and_a_cascading_fk(): void
    {
        self::assertSame(
            [
                'id' => 'NO',
                'order_sync_id' => 'NO',
                'trigger' => 'NO',
                'run_id' => 'YES',
                'reason_code' => 'NO',
                'qbo_fault_code' => 'YES',
                'qbo_fault_element' => 'YES',
                'message' => 'YES',
                'created_at' => 'NO',
            ],
            self::nullability('order_sync_errors'),
        );

        $fk = DB::selectOne(<<<'SQL'
            SELECT confrelid::regclass::text AS target, confdeltype
            FROM pg_constraint
            WHERE conrelid = 'quickbooks.order_sync_errors'::regclass AND contype = 'f'
            SQL);
        self::assertNotNull($fk);
        self::assertSame('quickbooks.order_syncs', $fk->target);
        self::assertSame('c', $fk->confdeltype);
    }

    #[Test]
    public function order_sync_errors_trigger_check_allows_only_range_manual_and_schedule(): void
    {
        $syncId = $this->insertSync('realm-schema', 2147450103);
        DB::table('quickbooks.order_sync_errors')->insert(['order_sync_id' => $syncId, 'trigger' => 'range', 'reason_code' => 'a']);
        DB::table('quickbooks.order_sync_errors')->insert(['order_sync_id' => $syncId, 'trigger' => 'manual', 'reason_code' => 'b']);
        DB::table('quickbooks.order_sync_errors')->insert(['order_sync_id' => $syncId, 'trigger' => 'schedule', 'reason_code' => 'd']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('check constraint');

        DB::table('quickbooks.order_sync_errors')->insert(['order_sync_id' => $syncId, 'trigger' => 'webhook', 'reason_code' => 'c']);
    }

    #[Test]
    public function the_seed_holds_exactly_the_fifteen_keys_with_null_refs(): void
    {
        DB::table('quickbooks.mappings')->delete();

        self::runSeed();

        self::assertSame(self::SEEDED_KEYS, self::mappingEntities());
        self::assertSame(0, DB::table('quickbooks.mappings')->where(static function ($query): void {
            $query->whereNotNull('qbo_ref_id')
                ->orWhereNotNull('qbo_ref_name')
                ->orWhereNotNull('realm_id')
                ->orWhereNotNull('resolved_at');
        })->count());
    }

    #[Test]
    public function re_running_the_seed_keeps_a_resolved_ref_and_adds_no_row(): void
    {
        DB::table('quickbooks.mappings')->delete();
        self::runSeed();
        DB::table('quickbooks.mappings')->where('key', 'tax.standard')->update([
            'qbo_ref_id' => '42',
            'qbo_ref_name' => '20.0% S',
            'realm_id' => 'realm-schema',
            'resolved_at' => '2026-11-21 10:00:00+00',
        ]);

        self::runSeed();

        self::assertSame(self::SEEDED_KEYS, self::mappingEntities());
        $resolved = DB::table('quickbooks.mappings')->where('key', 'tax.standard')->sole();
        self::assertSame('42', $resolved->qbo_ref_id);
        self::assertSame('20.0% S', $resolved->qbo_ref_name);
        self::assertSame('realm-schema', $resolved->realm_id);
    }

    #[Test]
    public function supabase_roles_and_public_have_no_privilege_on_the_quickbooks_schema(): void
    {
        $roles = self::checkedRoles();
        self::assertContains('public', $roles);
        self::assertContains('service_role', $roles, 'The Supabase baseline roles are missing');

        $objects = DB::select(<<<'SQL'
            SELECT c.oid::regclass::text AS name, c.relkind
            FROM pg_class c
            JOIN pg_namespace n ON n.oid = c.relnamespace
            WHERE n.nspname = 'quickbooks' AND c.relkind IN ('r', 'v', 'S')
            SQL);
        self::assertGreaterThanOrEqual(5, \count($objects), 'Expected 3 tables, the view and the identity sequences');

        $granted = [];
        foreach ($roles as $role) {
            foreach (['USAGE', 'CREATE'] as $privilege) {
                if (self::has('has_schema_privilege(?, ?, ?)', [$role, 'quickbooks', $privilege])) {
                    $granted[] = "{$role} {$privilege} ON SCHEMA quickbooks";
                }
            }

            foreach ($objects as $object) {
                $sequence = $object->relkind === 'S';
                $privileges = $sequence
                    ? ['USAGE', 'SELECT', 'UPDATE']
                    : ['SELECT', 'INSERT', 'UPDATE', 'DELETE', 'TRUNCATE', 'REFERENCES', 'TRIGGER'];
                $function = $sequence ? 'has_sequence_privilege' : 'has_table_privilege';

                foreach ($privileges as $privilege) {
                    if (self::has("{$function}(?, ?, ?)", [$role, $object->name, $privilege])) {
                        $granted[] = "{$role} {$privilege} ON {$object->name}";
                    }
                }
            }
        }

        self::assertSame([], $granted);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertSync(string $realmId, int $reference, array $overrides = []): string
    {
        $id = DB::table('quickbooks.order_syncs')->insertGetId([
            'realm_id' => $realmId,
            'reference' => $reference,
            'order_external_id' => $reference,
            ...$overrides,
        ]);
        self::assertIsString($id);

        return $id;
    }

    /**
     * @return array<string, string>
     */
    private static function nullability(string $table): array
    {
        $columns = [];
        foreach (DB::select(
            "SELECT column_name, is_nullable FROM information_schema.columns
             WHERE table_schema = 'quickbooks' AND table_name = ? ORDER BY ordinal_position",
            [$table],
        ) as $column) {
            $columns[$column->column_name] = $column->is_nullable;
        }

        return $columns;
    }

    /**
     * @return array<string, string>
     */
    private static function mappingEntities(): array
    {
        /** @var array<string, string> $rows */
        $rows = DB::table('quickbooks.mappings')->orderBy('id')->pluck('qbo_entity', 'key')->all();

        return $rows;
    }

    private static function runSeed(): void
    {
        $migration = require \base_path(self::SEED_MIGRATION);
        self::assertInstanceOf(Migration::class, $migration);
        self::assertTrue(\method_exists($migration, 'up'));
        $migration->up();
    }

    /**
     * @return list<string>
     */
    private static function checkedRoles(): array
    {
        $existing = DB::table('pg_roles')
            ->whereIn('rolname', ['anon', 'authenticated', 'service_role'])
            ->whereRaw('rolname <> current_user')
            ->pluck('rolname')
            ->all();
        /** @var list<string> $existing */

        return ['public', ...$existing];
    }

    /**
     * @param list<string> $bindings
     */
    private static function has(string $call, array $bindings): bool
    {
        $row = DB::selectOne("SELECT {$call} AS granted", $bindings);
        self::assertNotNull($row);

        return (bool) $row->granted;
    }
}
