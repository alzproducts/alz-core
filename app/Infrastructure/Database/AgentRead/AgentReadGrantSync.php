<?php

declare(strict_types=1);

namespace App\Infrastructure\Database\AgentRead;

use App\Infrastructure\Database\AgentRead\Exceptions\AgentReadAllowlistException;
use App\Infrastructure\Database\DatabaseGateway;
use Illuminate\Database\ConnectionInterface;
use LogicException;

/**
 * Makes the Agent database login's privileges exactly the Read allowlist: revokes everything the role holds, then
 * grants USAGE on each allowlisted schema and column-level SELECT on each allowlisted column, in one transaction.
 *
 * Runs after every migrate (docs/adr/0016-migrate-on-the-supabase-baseline.md), so a recreated view regains its
 * grants and a hand-made grant is undone on the next deploy.
 */
final readonly class AgentReadGrantSync
{
    public const string ROLE = 'agent_readonly';

    private const string CONNECTION = 'pgsql';

    private const string REVOCATIONS = <<<'SQL'
        WITH target AS (SELECT oid, rolname FROM pg_roles WHERE rolname = ?)
        SELECT format('REVOKE %I FROM %I', m.roleid::regrole::text, t.rolname) AS statement
        FROM pg_auth_members m, target t WHERE m.member = t.oid
        UNION
        SELECT format('REVOKE ALL ON DATABASE %I FROM %I', d.datname, t.rolname)
        FROM pg_database d, aclexplode(d.datacl) a, target t WHERE a.grantee = t.oid
        UNION
        SELECT format('REVOKE ALL ON SCHEMA %I FROM %I', n.nspname, t.rolname)
        FROM pg_namespace n, aclexplode(n.nspacl) a, target t WHERE a.grantee = t.oid
        UNION
        SELECT format('REVOKE ALL ON %s %s FROM %I', CASE c.relkind WHEN 'S' THEN 'SEQUENCE' ELSE 'TABLE' END, c.oid::regclass, t.rolname)
        FROM pg_class c, aclexplode(c.relacl) a, target t WHERE a.grantee = t.oid
        UNION
        SELECT format('REVOKE ALL (%I) ON TABLE %s FROM %I', at.attname, at.attrelid::regclass, t.rolname)
        FROM pg_attribute at, aclexplode(at.attacl) a, target t WHERE a.grantee = t.oid
        UNION
        SELECT format('REVOKE ALL ON ROUTINE %s FROM %I', p.oid::regprocedure, t.rolname)
        FROM pg_proc p, aclexplode(p.proacl) a, target t WHERE a.grantee = t.oid
        UNION
        SELECT format('REVOKE ALL ON TYPE %s FROM %I', ty.oid::regtype, t.rolname)
        FROM pg_type ty, aclexplode(ty.typacl) a, target t WHERE a.grantee = t.oid
        SQL;

    private const string COLUMN_TYPES = <<<'SQL'
        SELECT n.nspname || '.' || c.relname || '.' || a.attname AS name, t.typname AS type
        FROM pg_class c
        JOIN pg_namespace n ON n.oid = c.relnamespace
        JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum > 0 AND NOT a.attisdropped
        JOIN pg_type t ON t.oid = a.atttypid
        WHERE n.nspname = ANY (?::text[]) AND c.relkind IN ('r', 'v', 'm', 'p', 'f')
        SQL;

    public function __construct(
        private DatabaseGateway $gateway,
        private AgentReadAllowlistRules $rules,
    ) {}

    public function sync(AgentReadAllowlist $allowlist): void
    {
        $db = $this->gateway->connection(self::CONNECTION);

        $db->transaction(function () use ($db, $allowlist): void {
            if ($db->selectOne('SELECT 1 FROM pg_roles WHERE rolname = ?', [self::ROLE]) === null) {
                throw new LogicException('The Agent database login ' . self::ROLE . ' does not exist; its migration has not run');
            }

            $violations = $this->rules->violations($allowlist, $this->columnTypes($db, $allowlist));
            if ($violations !== []) {
                throw new AgentReadAllowlistException($violations);
            }

            self::revokeEverything($db);
            self::grantAllowlist($db, $allowlist);
        });
    }

    private static function revokeEverything(ConnectionInterface $db): void
    {
        /** @var list<object{statement: string}> $rows */
        $rows = $db->select(self::REVOCATIONS, [self::ROLE]);

        foreach ($rows as $row) {
            $db->statement($row->statement);
        }
    }

    private static function grantAllowlist(ConnectionInterface $db, AgentReadAllowlist $allowlist): void
    {
        $role = self::identifier(self::ROLE);

        foreach ($allowlist->schemas() as $schema) {
            $db->statement('GRANT USAGE ON SCHEMA ' . self::identifier($schema) . " TO {$role}");
        }

        foreach ($allowlist->relations as $relation => $columns) {
            $list = \implode(', ', \array_map(self::identifier(...), $columns));
            $qualified = \implode('.', \array_map(self::identifier(...), \explode('.', $relation, 2)));
            $db->statement("GRANT SELECT ({$list}) ON TABLE {$qualified} TO {$role}");
        }
    }

    /**
     * @return array<string, string>
     */
    private function columnTypes(ConnectionInterface $db, AgentReadAllowlist $allowlist): array
    {
        $schemas = \array_values(\array_intersect($allowlist->schemas(), AgentReadAllowlistRules::SCHEMAS));

        /** @var list<object{name: string, type: string}> $rows */
        $rows = $db->select(self::COLUMN_TYPES, ['{' . \implode(',', $schemas) . '}']);

        return \array_column($rows, 'type', 'name');
    }

    private static function identifier(string $name): string
    {
        return '"' . \str_replace('"', '""', $name) . '"';
    }
}
