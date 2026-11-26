# A QuickBooks order sync is one ShopWired order reference per QBO company, posted once

The sync unit is the ShopWired order `reference` within one QBO company: the sync row is unique on (`realm_id`, `reference`), so each reference has at most one **order sync** per company and records at most one posted document. An order edited in ShopWired keeps its reference, so a recorded post is never repeated. A post that reaches QBO but goes unrecorded can still be posted again by a re-attempt; ADR-0019 covers that window. M1 never updates or voids a posted document: a row that reached `synced` is final, and a later trigger for its reference reports `already_synced`. Claiming a row follows the lease-and-return model of ADR-0012: a worker claims a `pending` row with an atomic `pending → processing` update that writes a claim token and a lease, and recording the outcome must match that token. A row whose lease has expired is treated as `pending` again, and a `failed` row re-attempted with `force_retry` is claimed the same way: each claim is one atomic update conditioned on the row's current status and token, so only one worker wins it (COR-294). An automatic re-attempt of a chunk claims no row holding `outcome_unknown`, whatever its status, and that refusal is part of the same claim statement (COR-365).

## Considered Options

- Unique on `reference` alone: rejected because mappings are already company-scoped, and a newly connected company would see the first company's rows as synced and post nothing.
- Unique on the ShopWired `external_id`: rejected because an edited order gets a new `external_id` under the same reference, so the same sale would post twice.
- Updating or voiding the posted document when the order changes: rejected for M1 because the replaced Zap never does it; detecting a changed order is M2 work (COR-307).

## Consequences

- An order changed after it was posted keeps its first document in QBO until COR-307 adds change detection.
- `skipped` and `not_attempted` are outcomes of one trigger, never stored row states.
- Connecting a different QBO company starts with no synced rows.
