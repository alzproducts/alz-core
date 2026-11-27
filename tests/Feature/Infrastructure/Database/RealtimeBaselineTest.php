<?php

declare(strict_types=1);

namespace Tests\Feature\Infrastructure\Database;

use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\CoversNothing;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

/**
 * The Supabase baseline carries Realtime's tenant schema, as hosted Supabase does (ADR-0016).
 */
#[CoversNothing]
final class RealtimeBaselineTest extends TestCase
{
    use DatabaseTransactions;

    #[Test]
    public function realtime_tenant_migrations_have_run(): void
    {
        $row = DB::selectOne('SELECT count(*) AS applied FROM realtime.schema_migrations');
        self::assertNotNull($row);

        self::assertGreaterThan(0, $row->applied);
    }

    #[Test]
    public function postgres_can_execute_realtime_send(): void
    {
        self::assertTrue(self::has(
            'has_function_privilege(?, ?, ?)',
            ['postgres', 'realtime.send(jsonb, text, text, boolean)', 'EXECUTE'],
        ));
    }

    #[Test]
    public function postgres_can_insert_into_realtime_messages(): void
    {
        self::assertTrue(self::has('has_table_privilege(?, ?, ?)', ['postgres', 'realtime.messages', 'INSERT']));
    }

    #[Test]
    public function realtime_topic_exists(): void
    {
        self::assertTrue(self::has("to_regprocedure('realtime.topic()') IS NOT NULL", []));
    }

    #[Test]
    public function postgres_can_create_a_select_policy_on_realtime_messages(): void
    {
        self::assertSame('postgres', self::currentUser());

        DB::statement('CREATE POLICY realtime_baseline_probe ON realtime.messages FOR SELECT TO authenticated USING (true)');

        self::assertTrue(self::has(
            'EXISTS (SELECT 1 FROM pg_policies WHERE schemaname = ? AND tablename = ? AND policyname = ?)',
            ['realtime', 'messages', 'realtime_baseline_probe'],
        ));
    }

    #[Test]
    public function a_realtime_messages_partition_covers_today(): void
    {
        self::assertSame('postgres', self::currentUser());

        DB::insert(
            "INSERT INTO realtime.messages (topic, extension, inserted_at) VALUES (?, ?, now() AT TIME ZONE 'utc')",
            ['realtime-baseline-probe', 'broadcast'],
        );

        self::assertTrue(self::has('EXISTS (SELECT 1 FROM realtime.messages WHERE topic = ?)', ['realtime-baseline-probe']));
    }

    #[Test]
    public function realtime_control_plane_tables_stay_out_of_public(): void
    {
        $controlPlane = DB::table('pg_tables')->where('schemaname', '_realtime')->pluck('tablename')->all();
        self::assertContains('tenants', $controlPlane, "Realtime's control plane is not in schema _realtime");

        $inPublic = DB::table('pg_tables')
            ->where('schemaname', 'public')
            ->whereIn('tablename', $controlPlane)
            ->pluck('tablename')
            ->all();

        self::assertSame([], $inPublic);
    }

    private static function currentUser(): string
    {
        $row = DB::selectOne('SELECT current_user AS name');
        self::assertNotNull($row);

        return (string) $row->name;
    }

    /**
     * @param list<string> $bindings
     */
    private static function has(string $expression, array $bindings): bool
    {
        $row = DB::selectOne("SELECT {$expression} AS granted", $bindings);
        self::assertNotNull($row);

        return (bool) $row->granted;
    }
}
