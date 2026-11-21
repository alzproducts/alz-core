# A QuickBooks order sync is one ShopWired order reference per QBO company, posted once

The sync unit is the ShopWired order `reference` within one QBO company: the sync row is unique on (`realm_id`, `reference`), so each reference has at most one **order sync** per company and at most one posted document. An order edited in ShopWired keeps its reference, so it is never posted a second time. M1 never updates or voids a posted document: a row that reached `synced` is final, and a later trigger for its reference reports `already_synced`. Claiming a row follows the lease-and-return model of ADR-0012: a worker claims a `pending` row with an atomic `pending → processing` update that writes a claim token and a lease, and recording the outcome must match that token. A row whose lease has expired is treated as `pending` again (COR-294).

## Considered Options

- Unique on `reference` alone: rejected because mappings are already company-scoped, and a newly connected company would see the first company's rows as synced and post nothing.
- Unique on the ShopWired `external_id`: rejected because an edited order gets a new `external_id` under the same reference, so the same sale would post twice.
- Updating or voiding the posted document when the order changes: rejected for M1 because the replaced Zap never does it; detecting a changed order is M2 work (COR-307).

## Consequences

- An order changed after it was posted keeps its first document in QBO until COR-307 adds change detection.
- `skipped` and `not_attempted` are outcomes of one trigger, never stored row states.
- Connecting a different QBO company starts with no synced rows.
