# Transient log throttle: reducing Slack noise from API outages

During external API outages (Linnworks, ShopWired, HelpScout, etc.), every transient failure fires a `Log::error()`. Since Slack's log channel filters at error level, a single service outage generates dozens of identical alerts per minute — one per retry attempt per job — with no actionable information beyond "the service is still down." These alerts are non-actionable noise that drowns out real issues.

The `TransientLogThrottle` is a log-level circuit breaker that sits between error handlers and Laravel's logger. On the first transient failure for a service, it logs at `error` (triggering Slack) and opens a time window. Subsequent failures within that window log at `warning` instead (visible in logs, silent on Slack). The window uses exponential backoff: 5 → 10 → 20 → 30 minutes (capped), with a 60-minute escalation TTL that resets after sustained quiet.

We chose Redis-backed state (`Cache::add()` for atomic first-failure detection) over in-memory counters because Octane workers and queue processes are separate processes — in-memory state wouldn't aggregate across them. The throttle degrades gracefully: if Redis is unreachable, it falls through to `Log::error()` on every failure, matching pre-throttle behaviour.

The throttle is independent of `ServiceCircuitBreaker`, which controls whether jobs are dispatched at all. The circuit breaker prevents wasted work; the log throttle prevents wasted attention. A service can be circuit-broken (no jobs dispatched) while the throttle independently manages log levels for failures that slip through the edges.

Only transient failures are throttled: server errors (5xx), connection timeouts, and unexpected SDK exceptions. Permanent failures (bad request, auth, not found) always log at error because they indicate programming bugs or configuration issues that need immediate attention.
