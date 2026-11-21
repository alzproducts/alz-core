# Rotating credentials refresh only under a fail-closed lock

Some vendors rotate a credential on every use: QuickBooks Online returns a new refresh token with each token refresh, and the old one stops working. alz-core stores the new value and must send it next time. Two refreshes that read the same stored token race: the loser sends a token the winner already spent, Intuit answers `invalid_grant`, and the connection is gone until a human reconnects it.

So a credential that the vendor rotates and alz-core writes back is refreshed only while holding the fail-closed `LockManagerInterface` lock. If the lock cannot be acquired (a wait timeout or a cache-store failure), the refresh throws and sends nothing. Under the lock, the refresh re-reads the stored credential, so a waiter sends the value the lock holder just stored. Canonical: `QuickBooksTokenService` (lock `quickbooks-token-refresh`). Disconnecting takes the same lock, so an in-flight refresh cannot write a deleted connection back.

`LockableCache` ([ADR-0005](0005-two-tier-cache-abstractions.md)) stays for credentials held in env, such as the Bing Ads and Linnworks sessions, where a duplicate refresh only costs a request.

## Considered Options

- **`LockableCache` for QBO tokens, as ADR-0005 suggests for OAuth tokens:** rejected. When the lock is unavailable it falls back to an unlocked refresh, which double-rotates the refresh token and forces a reconnect.
- **Amend ADR-0005 in place:** rejected. It would put two lock contracts with opposite failure modes into one record.

## Consequences

- A cache-store outage stops QBO token refreshes instead of risking the stored token; callers see `LockAcquisitionException` and retry later.
- A new integration whose vendor rotates a stored credential follows `QuickBooksTokenService`, not `LockableCache`.
