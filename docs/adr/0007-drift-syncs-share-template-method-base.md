# Drift syncs share a template-method use case base

The eight drift syncs (three label syncs, five filter syncs) share one pipeline — log → drift query → early-return → per-product dispatch → count log — via an abstract template-method base (`AbstractDriftSyncUseCase`), mirroring the `AbstractSyncEntityWebhookUseCase` precedent. Each vertical remains its own `Sync*UseCase` with its own typed `execute()`, drift-query interface, and schedule entry; only the correction mechanism is shared.

This deliberately deviates from the codebase's default posture that use cases stay independent and duplication between them is acceptable. That bar exists because use-case similarity is usually *policy* similarity, which diverges; it is cleared here because the eight bodies vary only by *data* — every business rule (what counts as drift) lives in the SQL views behind the drift-query interfaces, and the use case bodies are pure mechanism. Eight copies written months apart showed zero structural divergence, while drifting on exactly the details a template pins down (log phrasing, count breakdowns, empty-drift exit logging).

## Consequences

- A ninth drift vertical is a drift query + a small subclass, not an 8-file vertical with a cloned test file.
- A vertical that stops fitting the shape (e.g. needs batch writes instead of per-product dispatch) must not be contorted to fit — it simply doesn't extend the base.
- Reversal signal: the first boolean flag or interacting hook added to the template means the abstraction was wrong; inline the pipeline back into the subclasses rather than growing it.
