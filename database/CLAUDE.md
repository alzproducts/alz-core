# Database Guidelines

## Octane Safety

**Never use `static` variables in connection callbacks** - they persist across Octane requests, causing security issues. Use Laravel Context or just run the operation every time.

## Connections

- `pgsql` - Migrations/seeders (no RLS)
- `pgsql_rls` - Default, user-scoped queries
- `pgsql_admin` - Admin ops, clears stale claims

Connection name determines callback behavior, not config.

## Multi-Schema Tables

Eloquent models need explicit schema: `protected $table = 'access.roles';`