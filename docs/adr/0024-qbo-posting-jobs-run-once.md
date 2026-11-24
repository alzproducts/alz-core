# 0024: A job that posts to QuickBooks runs once; waits and re-attempts are fresh copies

A queued job that can cause a QuickBooks document to be posted sets `$tries = 1`, and never calls `release()` or uses a middleware that does. Waiting for a concurrency slot or re-attempting unsent work dispatches a fresh message that carries its own attempt number and only the references still unsent. The use case, not the queue, owns the number of attempts.

A payload redelivered by the queue fails before `handle()`. It must not run again, because an earlier delivery may have left a document in flight for about 120 seconds that a second run would post again.

## Considered Options
- `release()` with a delay: rejected because each release counts as an attempt, so a chunk that only waited would fail.
- `$tries = 0` with `$maxExceptions`: rejected because the crash count depends on a cache marker that can be lost.
- Queue-level retries with backoff: rejected because they repeat the whole payload, including `force_retry`.

## Consequences
- An operator's `queue:retry` or Horizon retry resets the attempt count, so it must not be used on these jobs; staff re-run the range instead.
- Failure recovery is a log entry naming the run and its unfinished references, not an automatic retry.
- These jobs do not use `HandleApiExceptions`, which releases on a transient failure.
