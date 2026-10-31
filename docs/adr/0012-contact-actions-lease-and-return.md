# Contact submission actions use lease-and-return, with the row as the source of truth

`customer_service.contact_submission_actions` keeps its four statuses. `processing` means only that a worker holds an action claim on a lease. An action waiting out a retry returns to `pending` with `next_attempt_at`, and a worker claims it with an atomic `pending → processing` update that writes a claim token. Completing, failing and returning to `pending` must match that token, and the sweep returns an expired lease to `pending` before re-dispatching it. The attempt budget and every status transition are enforced on the row, not by the queued job, because the stale action sweep and Laravel's delayed release can both put a job on the queue for the same action, and only the row can make the second one a no-op (COR-235).

## Considered Options

- A distinct `retrying` status: rejected because the dashboard views' status CASE blocks have no ELSE, so a new value shows as NULL until the views, `ActionStatus`, the CHECK constraint, the partial indexes and the frontend all change.
- Per-type sweepers over the old states: rejected because a sweep threshold cannot tell a crashed worker from a row in a 12h backoff, so recovery is slow and two jobs can still run for one action.

## Consequences

- Every action row holds what is needed to re-dispatch it, including quote value and converted-at time.
- Call-tracking actions adopt the same model separately (COR-250).
