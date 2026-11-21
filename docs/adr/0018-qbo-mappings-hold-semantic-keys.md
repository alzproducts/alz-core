# QBO mappings hold semantic keys seeded by migration, resolved per company

The document builder emits **mapping keys**: semantic names for a tax treatment, a payment route or a fixed ref, never a QBO entity ID. Mapping tables hold those keys, seeded by migration, and each company-scoped mapping row resolves a key to the QBO entity ref of the connected company at runtime. Committed text holds no QBO ID: not in migrations, config, code, tests or docs. A sync for which any seeded key is unmapped for the connected company fails before any QBO call, returning 412 `precondition_failed` with the missing keys; an order that needs a key the migration never seeded fails alone with `mapping_missing` (COR-294).

## Considered Options

- Hard-coding the replaced Zap's QBO IDs in code or config: rejected because the IDs belong to one company, the repo is public, and a sandbox or second company would post against the wrong entities.

## Consequences

- Adding a key is a migration; its QBO ref is resolved per company.
- A sandbox company and the production company each carry their own mapping rows.
- How mappings are edited changes in M3.
