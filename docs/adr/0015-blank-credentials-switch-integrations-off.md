# Blank credentials switch integrations off

A local checkout holds no production credentials, and the app must still run. An integration binds its real client only when all its secret credentials are set (`IntegrationCredentials`). With any blank, it binds a **stand-in** outside production; in production the binding and the boot check throw `InvalidConfigurationException` naming the key. Identifiers (client ids, channel names and the like) are not part of the switch.

Stand-ins exist only where a local flow must finish, because the contact and lead flows are queued and fail silently: HelpScout contact writes (with the retry lookup and a placeholder system user id), Slack notifications, and Google Ads and Bing conversions. The conversion stand-ins answer `platform()`, `supports()` and `extractClickId()` as the real adapters do, so eligibility is unchanged. A stand-in writes one info line per call (`Integration stand-in swallowed a call`, ids only). Every other integration fails on use when blank.

## Considered Options

- **Per-integration `*_LIVE` flags:** rejected. An agent can change them, each is a route to production, and nothing forced the next integration to join.
- **A stand-in for every client interface:** rejected. About 40 classes for little value.
- **Nullable clients:** rejected. Every consumer would need a null check.

## Consequences

- A mis-set `APP_ENV` outside `production` would swallow contact forms silently, so `config/app.php` keeps `production` as the `APP_ENV` default.
- Verify blanks those integrations' keys to drive the contact and lead journeys, and proves them from the log lines.
- A new integration that a local flow must finish gets a stand-in here and its secrets in `IntegrationCredentials`.
